<?php
/* ===========================================================================
   SUPPEX admin — create / edit a product
   ---------------------------------------------------------------------------
   The screen the client asked for by name: images, names, descriptions,
   prices, flavours, sizes and nutrition, all editable without touching code.

   Flavours, sizes and nutrition rows are posted as parallel arrays
   (flavor_id[], flavor_name[]) and rewritten wholesale on save. Diffing them
   row by row would mean giving every row a hidden database id and handling
   the three-way create/update/delete case for a table that never holds more
   than a handful of rows.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/variants.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$id = (int) ($_GET['id'] ?? 0);
if ($id === 0 && !empty($_GET['slug'])) {
    $id = (int) db_value('SELECT id FROM products WHERE slug = ?', [(string) $_GET['slug']]);
}

$isNew   = $id === 0;
$product = $isNew ? null : db_one('SELECT * FROM products WHERE id = ?', [$id]);

if (!$isNew && $product === null) {
    http_response_code(404);
    admin_head('محصول پیدا نشد', ['user' => $user]);
    echo '<div class="card"><div class="empty">این محصول وجود ندارد.</div></div>';
    admin_foot();
    exit;
}

$errors = [];

/* --------------------------------------------------------------------------
   Image actions — delete one, or promote one to the main image
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['del_image', 'main_image'], true)) {
    csrf_check();
    $imgId = (int) ($_POST['image_id'] ?? 0);
    $row   = db_one('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$imgId, $id]);

    if ($row !== null) {
        if ($_POST['action'] === 'del_image') {
            db_query('DELETE FROM product_images WHERE id = ?', [$imgId]);
            image_delete($row['path']);

            /* If the deleted file was the main image, promote whatever is left
               rather than leaving the storefront with a broken thumbnail. */
            if ($product['image'] === $row['path']) {
                $next = (string) (db_value(
                    'SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1',
                    [$id]
                ) ?? '');
                db_query('UPDATE products SET image = ? WHERE id = ?', [$next, $id]);
            }
            flash('ok', 'تصویر حذف شد.');
        } else {
            db_query('UPDATE products SET image = ? WHERE id = ?', [$row['path'], $id]);
            flash('ok', 'تصویر اصلی تغییر کرد.');
        }
    }

    header('Location: product-edit.php?id=' . $id);
    exit;
}

/* --------------------------------------------------------------------------
   Save
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check();

    $nameFa = clean_text($_POST['name_fa'] ?? '', 180);
    $nameEn = clean_text($_POST['name_en'] ?? '', 180);
    $price  = parse_money($_POST['price'] ?? '');

    if ($nameFa === '') { $errors['name_fa'] = 'نام فارسی محصول لازم است.'; }

    /* The typed price is required only in manual mode. In dirham mode the
       formula produces it, and insisting on a number that is about to be
       overwritten only invites someone to type a wrong one. */
    $wantsAed = (($_POST['price_mode'] ?? 'manual') === 'aed');
    if ($price <= 0 && !$wantsAed) { $errors['price'] = 'قیمت را وارد کنید.'; }

    /* And overwritten it now is - on this save, not at the next change of
       rate. In dirham mode with a rate on record, pricing_reprice_product()
       below prices every unit of this product at that rate. It is the rate
       the rest of the shelf is priced at (aed_rate is only ever written by an
       apply), so the product lands exactly where the pricing page would have
       put it. With no rate there is nothing to multiply by, and the typed
       price stands until the pricing page sets one. */
    $rateNow   = pricing_rate();
    $autoPrice = $wantsAed && $rateNow !== null;

    $compareRaw = trim((string) ($_POST['compare_at'] ?? ''));
    $compareAt  = $compareRaw === '' ? null : parse_money($compareRaw);
    /* In dirham mode the price is not known until the formula has run, so a
       typed struck price is checked against it after the save, not here - it
       is kept in compare_manual (below) and simply not shown while the price
       is at or above it. */
    if (!$autoPrice && $compareAt !== null && $compareAt > 0 && $compareAt <= $price) {
        /* Otherwise the storefront would render a "discount" of 0% or a
           negative one — the badge is derived from these two numbers. */
        $errors['compare_at'] = 'قیمت قبل از تخفیف باید بیشتر از قیمت فعلی باشد.';
    }
    if ($compareAt === 0) { $compareAt = null; }

    $badges = [];
    /* topdeal is «تخفیف برتر»: up to three of these stand in the home page's
       hero. A badge like the other two, so it needed no new column. */
    foreach (['bestseller', 'new', 'topdeal'] as $b) {
        if (!empty($_POST['badge_' . $b])) { $badges[] = $b; }
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;

    /* --- Dirham pricing ---------------------------------------------------
       A product is dirham-linked only when it has a dirham cost to work from.
       Allowing the mode without one would leave the product invisible to the
       pricing page and its price frozen forever, with nothing on screen
       explaining why. */
    $priceMode   = (($_POST['price_mode'] ?? 'manual') === 'aed') ? 'aed' : 'manual';
    $costAed     = pricing_parse_aed((string) ($_POST['cost_aed'] ?? ''));
    $profitToman = parse_money($_POST['profit_toman'] ?? '');

    /* The discount, as an amount off: the pricing page's model and the import
       sheet's «تخفیف (تومان)» column. Read only when the form carries the
       field - a page opened before it existed must not read as «no discount»
       and erase one the import set. */
    $promoGiven = array_key_exists('promo_toman', $_POST);
    $promoToman = parse_money($_POST['promo_toman'] ?? '');

    if ($priceMode === 'aed') {
        if ($costAed === null || $costAed <= 0) {
            $errors['cost_aed'] = 'برای قیمت‌گذاری درهمی، قیمت خرید به درهم لازم است.';
        } elseif ($costAed > PRICING_MAX_COST_AED) {
            $errors['cost_aed'] = 'قیمت خرید ' . number_format($costAed)
                . ' درهم غیرواقعی است — احتمالاً به تومان وارد شده.';
        }
        if ($profitToman <= 0) {
            $errors['profit_toman'] = 'مقدار سود روی این محصول را وارد کنید.';
        }
    }
    if ($costAed !== null && $costAed <= 0) { $costAed = null; }
    if ($profitToman <= 0) { $profitToman = null; }

    /* «سود شما روی این محصول» is typed in toman, but what the shop actually
       wants to keep is the RATIO it represents: a fixed toman profit means the
       margin shrinks every time the dirham climbs, and every shelf price rises
       while it happens, so nothing looks wrong until the year's numbers do.
       The ratio is worked out here, from the cost this profit was typed
       against, and re-derives the toman figure at every later rate.

       Needs a rate to turn a dirham cost into a toman one. On a shop where no
       rate has been entered yet this stays null, and pricing_apply() locks the
       ratio in the first time a rate is applied. */
    $profitPercent = null;
    if ($priceMode === 'aed' && $costAed !== null && $profitToman !== null) {
        /* Only when the profit actually CHANGED.

           Recomputing unconditionally meant the ratio drifted every time the
           product was saved for any reason: the stored toman profit is a
           snapshot from the last apply, so dividing it by today's higher cost
           yields a smaller ratio each time the dirham rises. A product locked
           at 50% came back 37.9% after a save that changed nothing but a
           description — and every later reprice honoured the lower figure. */
        $prevProfit = $id > 0
            ? db_value('SELECT profit_toman FROM products WHERE id = ?', [$id])
            : null;
        $prevPct = $id > 0
            ? db_value('SELECT profit_percent FROM products WHERE id = ?', [$id])
            : null;

        if ($prevPct !== null && $prevProfit !== null
            && (int) $prevProfit === (int) $profitToman) {
            $profitPercent = (float) $prevPct;
        } else {
            $rateNow = pricing_rate();
            if ($rateNow !== null && $rateNow > 0) {
                $profitPercent = pricing_percent_from(
                    (int) round($costAed * $rateNow), (int) $profitToman);
            }
        }
    } elseif ($priceMode !== 'aed') {
        /* A toman-bought line does not track the dirham, so a ratio would be a
           claim about something that never moves. */
        $profitPercent = null;
    }

    /* Blank means "use the shop-wide rate", which is not the same as 0 — a zero
       override says this product pays no commission at all, and that has to be
       something someone typed on purpose rather than something a blank field
       decays into. */
    $commRaw = trim(to_latin_digits((string) ($_POST['commission_percent'] ?? '')));
    $commissionOverride = null;
    if ($commRaw !== '') {
        $commissionOverride = round((float) str_replace(',', '', $commRaw), 2);
        if ($commissionOverride < 0 || $commissionOverride > 100) {
            $errors['commission_percent'] = 'درصد باید بین ۰ تا ۱۰۰ باشد.';
        }
    }

    /* The same bound on every other dirham figure the page takes - a size's
       purchase price, a flavour's premium, a combination's purchase price -
       since each of them now reaches a shelf price on this very save. A column
       drawn in toman (the page was opened in the other mode) is not read as
       dirhams at all; see $flavorUnitOk and $gridUnitOk below. */
    if ($priceMode === 'aed') {
        $aedFigures = (array) ($_POST['size_cost_aed'] ?? []);
        if ((string) ($_POST['flavor_unit'] ?? 'aed') === 'aed') {
            $aedFigures = array_merge($aedFigures, (array) ($_POST['flavor_extra'] ?? []));
        }
        if ((string) ($_POST['grid_unit'] ?? 'aed') === 'aed') {
            $aedFigures = array_merge($aedFigures, (array) ($_POST['variant_cost'] ?? []));
        }
        $worstAed = 0.0;
        foreach ($aedFigures as $rawAed) {
            $worstAed = max($worstAed, (float) (pricing_parse_aed((string) $rawAed) ?? 0));
        }
        if ($worstAed > PRICING_MAX_COST_AED) {
            $errors['_'] = 'یکی از ارقام درهمیِ اندازه‌ها، طعم‌ها یا ترکیب‌ها '
                . number_format($worstAed) . ' است — احتمالاً به تومان وارد شده. '
                . 'درستش کنید و دوباره ذخیره کنید.';
        }
    }

    /* The slug is the product's URL. It is generated once from the name and
       then left alone: changing it silently breaks every link anyone has
       already shared. Editing it stays possible, deliberately, but by hand. */
    $slugInput = clean_text($_POST['slug'] ?? '', 120);
    $slugBase  = $slugInput !== '' ? slugify($slugInput) : slugify($nameEn !== '' ? $nameEn : $nameFa);

    if (!$errors) {
        $costTomanRaw = trim(to_latin_digits((string) ($_POST['cost_toman'] ?? '')));
        $costToman    = $costTomanRaw === '' ? null : parse_money($costTomanRaw);
        $existingCostToman = $isNew ? null
            : db_value('SELECT cost_toman FROM products WHERE id = ?', [$id]);
        $existingCostToman = $existingCostToman === null ? null : (int) $existingCostToman;

        $fields = [
            'slug'              => unique_slug($slugBase, 'products', $isNew ? null : $id),
            'name_fa'           => $nameFa,
            'name_en'           => $nameEn,
            'brand'             => clean_text($_POST['brand'] ?? '', 120),
            'category_id'       => $categoryId,
            'price'             => $price,
            'compare_at'        => $compareAt,
            'in_stock'          => empty($_POST['in_stock']) ? 0 : 1,
            'badges'            => implode(',', $badges),
            'variant_label'     => clean_text($_POST['variant_label'] ?? '', 180),
            'short'             => clean_text($_POST['short'] ?? '', 500),
            'description'       => clean_multiline($_POST['description'] ?? '', 5000),
            'features'          => json_encode_pretty(lines_to_array($_POST['features'] ?? '')),
            'ingredients'       => clean_multiline($_POST['ingredients'] ?? '', 2000),
            'usage_text'        => clean_multiline($_POST['usage_text'] ?? '', 2000),
            'nutrition_serving' => clean_text($_POST['nutrition_serving'] ?? '', 180),
            'sort_order'        => (int) ($_POST['sort_order'] ?? 0),
            'is_active'         => empty($_POST['is_active']) ? 0 : 1,

            /* Dirham-linked pricing. cost_aed is a decimal, so it keeps its
               fractional part — dirham prices are routinely quoted as 42.50,
               and rounding that to 42 loses about 1% of the cost basis on
               every single unit. */
            'cost_aed'          => $costAed,
            /* What the unit cost in toman. For a dirham purchase this is
               derived - cost_aed times the rate the price was applied at - and
               the typed value is ignored, because two costs for one purchase
               is two different claims. For a purchase made inside Iran it is
               the only cost there is, and without it every order books the
               whole price as profit. */
            'cost_toman'        => $costAed !== null ? $existingCostToman : $costToman,
            'profit_toman'      => $profitToman,
            'profit_percent'    => $profitPercent,
            'price_mode'        => $priceMode,
            'commission_percent' => $commissionOverride,
        ];

        /* Written only by a dirham save that carried the field. A manual
           product keeps whatever discount the import gave it, untouched. */
        if ($priceMode === 'aed' && $promoGiven) {
            $fields['promo_toman'] = $promoToman > 0 ? $promoToman : null;
        }

        /* A struck price typed by hand on a dirham product. Every repricing
           rewrites compare_at, so the typed figure is kept apart and put back
           beside the price it belongs to by pricing_place_manual_compare().
           Blank hands the struck price back to the discount. Until the
           migration has added the column the field stays the discount's. */
        if ($priceMode === 'aed' && pricing_manual_ready()) {
            $fields['compare_manual'] = ($compareAt !== null && $compareAt > 0) ? $compareAt : null;
        }

        /* Nutrition rows arrive as two parallel arrays; empty labels are
           dropped so a blank template row does not become a blank table row. */
        $nRows   = [];
        $nLabels = (array) ($_POST['nutrition_label'] ?? []);
        $nValues = (array) ($_POST['nutrition_value'] ?? []);
        foreach ($nLabels as $i => $label) {
            $label = clean_text((string) $label, 80);
            $value = clean_text((string) ($nValues[$i] ?? ''), 40);
            if ($label !== '') {
                $nRows[] = ['label' => $label, 'value' => $value];
            }
        }
        $fields['nutrition_rows'] = json_encode_pretty($nRows);

        /* Read before writing, so the log records what it actually changed
           from. Captured here rather than after the UPDATE for the obvious
           reason. */
        $previousComm = $isNew
            ? null
            : db_value('SELECT commission_percent FROM products WHERE id = ?', [$id]);

        $columns = array_keys($fields);
        $priced  = null;
        $droppedPremium = false;
        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($isNew) {
                $sql = 'INSERT INTO products (' . implode(', ', $columns) . ') VALUES ('
                     . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $id = db_insert($sql, array_values($fields));
            } else {
                $sql = 'UPDATE products SET '
                     . implode(', ', array_map(static fn($c) => $c . ' = ?', $columns))
                     . ' WHERE id = ?';
                db_query($sql, array_merge(array_values($fields), [$id]));
            }

            /* Variants: replace rather than diff.

               Which is why the pictures have to be read out FIRST. They are
               uploaded one flavour at a time and no form field carries them
               back, so without this the rebuild would drop every one of them
               on a save that changed nothing but a description. Keyed by name,
               the same way lib/import.php keeps the premiums. */
            $keptFlavorImg = [];
            foreach (db_all('SELECT name, image FROM product_flavors WHERE product_id = ?',
                            [$id]) as $oldF) {
                $keptFlavorImg[(string) $oldF['name']] = (string) $oldF['image'];
            }

            /* The premium column is labelled in the unit of the mode the page
               was drawn in. Switch the mode and save, and it posts a toman
               premium as a dirham one - 150,000 dirhams on a flavour, priced on
               this same save. Such a figure is dropped instead, and said so. */
            $unitNow      = $priceMode === 'aed' ? 'aed' : 'toman';
            $flavorUnitOk = (string) ($_POST['flavor_unit'] ?? $unitNow) === $unitNow;
            $gridUnitOk   = (string) ($_POST['grid_unit'] ?? $unitNow) === $unitNow;

            db_query('DELETE FROM product_flavors WHERE product_id = ?', [$id]);
            $fIds   = (array) ($_POST['flavor_id'] ?? []);
            $fNames = (array) ($_POST['flavor_name'] ?? []);
            $fExtra = (array) ($_POST['flavor_extra'] ?? []);
            $fClear = (array) ($_POST['flavor_image_clear'] ?? []);
            foreach ($fNames as $i => $name) {
                $name = clean_text((string) $name, 120);
                if ($name === '') { continue; }
                $ext = clean_text((string) ($fIds[$i] ?? ''), 60);

                /* Three states, in order: a new upload wins, then an explicit
                   clear, then whatever was already there. An ordinary save is
                   the third one and must be invisible. */
                $fImage = $keptFlavorImg[$name] ?? '';
                if (!empty($fClear[$i])) {
                    $fImage = '';
                }
                if (isset($_FILES['flavor_image']['error'][$i])
                    && $_FILES['flavor_image']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    $flavorUpload = image_store([
                        'name'     => $_FILES['flavor_image']['name'][$i],
                        'type'     => $_FILES['flavor_image']['type'][$i],
                        'tmp_name' => $_FILES['flavor_image']['tmp_name'][$i],
                        'error'    => $_FILES['flavor_image']['error'][$i],
                        'size'     => $_FILES['flavor_image']['size'][$i],
                    ]);
                    if ($flavorUpload['ok']) {
                        $fImage = $flavorUpload['path'];
                    } else {
                        /* Said out loud rather than swallowed: a picture that
                           did not save while the rest of the form did is the
                           kind of half-success nobody notices until a shopper
                           does. */
                        flash('error', $name . ' — ' . $flavorUpload['error']);
                    }
                }

                /* One field, in whichever unit this product is bought in.
                   Blank stays NULL rather than becoming zero: both leave the
                   price alone, but only one of them is a decision somebody
                   made, and the pricing page skips the NULLs. */
                $raw   = trim(to_latin_digits((string) ($fExtra[$i] ?? '')));
                $extra = $priceMode === 'aed'
                    ? pricing_parse_aed($raw)
                    : ($raw === '' ? null : (float) parse_money($raw));
                if ($extra !== null && $extra <= 0) { $extra = null; }
                if ($extra !== null && !$flavorUnitOk) {
                    $extra = null;
                    $droppedPremium = true;
                }

                db_query(
                    'INSERT INTO product_flavors
                        (product_id, ext_id, name, cost_delta_aed, price_delta_toman,
                         image, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$id, $ext !== '' ? $ext : slugify($name), $name,
                     $priceMode === 'aed' ? $extra : null,
                     $priceMode === 'aed' ? 0 : (int) round((float) ($extra ?? 0)),
                     $fImage, $i]
                );
            }

            /* The toman figure for a dirham product is derived, and the rewrite
               above just set it to zero. Recomputing beats preserving it: it is
               always derivable from the dirham figure and the rate, so a stored
               copy is only ever another thing that can fall out of step. */
            if ($priceMode === 'aed') {
                $rateNow = pricing_rate();
                if ($rateNow !== null && $rateNow > 0) {
                    pricing_sync_flavors($id, $rateNow);
                }
            }

            /* Sizes first, before the combinations are built from them.
               variants_adopt() reads the size rows from the database, and
               while this block ran after it a size added on this save got no
               combinations until the NEXT save - the grid showed its rows,
               the save dropped them, and they came back one save later. */
            /* Sizes are replaced wholesale so a row dropped from the form
               disappears. But replacing a row is not the same as forgetting
               what was on it: the agreed commission rate, the discount, and
               the cost the price was built from are all set elsewhere - by the
               catalogue import and the pricing page - and this form has no
               input for any of them. Until they were carried across, opening a
               product and pressing save without changing anything silently
               erased five values, including a rate that decides money moving
               between two parties.

               Keyed by ext_id, the only stable handle a size has. */
            $keep = [];
            foreach (db_all('SELECT ext_id, cost_toman, promo_toman, commission_percent,
                                    price_applied_rate, price_applied_at, stock_qty,
                                    profit_toman AS prev_profit, profit_percent
                               FROM product_sizes WHERE product_id = ?', [$id]) as $old) {
                $keep[(string) $old['ext_id']] = $old;
            }

            db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
            $sIds      = (array) ($_POST['size_id'] ?? []);
            $sPrevIds  = (array) ($_POST['size_prev_id'] ?? []);
            $sLabels   = (array) ($_POST['size_label'] ?? []);
            $sServings = (array) ($_POST['size_servings'] ?? []);
            $sPrices   = (array) ($_POST['size_price'] ?? []);
            $sCompares = (array) ($_POST['size_compare'] ?? []);
            $sCostAed  = (array) ($_POST['size_cost_aed'] ?? []);
            $sCostTom  = (array) ($_POST['size_cost_toman'] ?? []);
            $sProfit   = (array) ($_POST['size_profit'] ?? []);
            foreach ($sLabels as $i => $label) {
                $label = clean_text((string) $label, 120);
                if ($label === '') { continue; }
                $ext     = clean_text((string) ($sIds[$i] ?? ''), 60);
                $sPrice  = parse_money($sPrices[$i] ?? 0);
                $sCompRaw = trim((string) ($sCompares[$i] ?? ''));
                $sComp   = $sCompRaw === '' ? null : parse_money($sCompRaw);
                $serv    = (int) digits_only((string) ($sServings[$i] ?? ''));

                /* Each size carries its own dirham cost, because each is a
                   separate purchase in Dubai at a separate price. Left blank,
                   it falls back to the parent's figures at pricing time. */
                $sAed     = pricing_parse_aed((string) ($sCostAed[$i] ?? ''));
                $sProfitT = parse_money($sProfit[$i] ?? '');

                $extFinal = $ext !== '' ? $ext : slugify($label);

                /* Matched on the id the row arrived with, falling back to the
                   one it is being saved under. Renaming a size must not quietly
                   drop the rate agreed on it. */
                $prevId = clean_text((string) ($sPrevIds[$i] ?? ''), 60);
                $prev   = $keep[$prevId] ?? ($keep[$extFinal] ?? null);

                /* Same rule as the product row: a dirham cost derives the toman
                   one, so the derived figure is kept rather than overwritten by
                   whatever is in a field that does not apply. */
                $sCostTomRaw = trim(to_latin_digits((string) ($sCostTom[$i] ?? '')));
                $sCostToman  = ($sAed !== null && $sAed > 0)
                    ? ($prev['cost_toman'] ?? null)
                    : ($sCostTomRaw === '' ? ($prev['cost_toman'] ?? null) : parse_money($sCostTomRaw));

                db_query(
                    'INSERT INTO product_sizes
                        (product_id, ext_id, label, servings, price, compare_at,
                         cost_aed, cost_toman, profit_toman, profit_percent, promo_toman,
                         commission_percent, price_applied_rate, price_applied_at,
                         stock_qty, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $extFinal, $label,
                     $serv > 0 ? $serv : null,
                     $sPrice > 0 ? $sPrice : $price,
                     ($sComp !== null && $sComp > 0) ? $sComp : null,
                     ($sAed !== null && $sAed > 0) ? $sAed : null,
                     $sCostToman,
                     $sProfitT > 0 ? $sProfitT : null,
                     /* The locked ratio survives a save, and is only re-derived
                        when the operator actually CHANGES the profit — which is
                        what the hint beside the field promises.

                        Rebuilt wholesale without this, every save reset the
                        ratio to NULL, pricing fell back to the fixed toman
                        profit it was introduced to replace, and the next apply
                        re-locked a new ratio from that stale figure against a
                        higher cost. Each edit-then-reprice cycle ratcheted the
                        margin down, permanently, and editing a description was
                        enough to trigger it. */
                     (function () use ($prev, $sProfitT, $sAed, $costAed) {
                         $sameProfit = (int) ($prev['prev_profit'] ?? 0) === (int) $sProfitT;
                         if ($sameProfit && ($prev['profit_percent'] ?? null) !== null) {
                             return $prev['profit_percent'];
                         }
                         /* Worked out against the cost the size is actually
                            priced from: its own purchase price, or the
                            product's when it has none. Asking only for its own
                            left a size that shares the product's purchase
                            price with no ratio at all, and the pricing page
                            then priced it by the PRODUCT's ratio - the profit
                            typed beside it was ignored. The same when a size's
                            profit was typed before any rate existed: it gets
                            its ratio now, as the product's own does. */
                         $basis = ($sAed !== null && $sAed > 0) ? $sAed : (float) ($costAed ?? 0);
                         $rate  = pricing_rate();
                         if ($rate === null || $rate <= 0 || $basis <= 0 || $sProfitT <= 0) {
                             return null;
                         }
                         return pricing_percent_from((int) round($basis * $rate), (int) $sProfitT);
                     })(),
                     $prev['promo_toman'] ?? null,
                     $prev['commission_percent'] ?? null,
                     $prev['price_applied_rate'] ?? null,
                     $prev['price_applied_at'] ?? null,
                     /* Carried, never posted. The count belongs to stock.php,
                        and the order flow changes it while this page is open —
                        a size input here would let a save that touched nothing
                        but a description undo somebody's purchase. */
                     $prev['stock_qty'] ?? null,
                     $i]
                );
            }

            /* --- attributes the owner defined --------------------------------

               Size and flavour are NOT touched here. They are written above from
               their own editors and mirrored into the attribute tables by
               variants_adopt(), so that one product's options live in one place
               without two forms fighting over the same rows. */
            $attrNew = clean_text((string) ($_POST['attr_new_name'] ?? ''), 120);
            if ($attrNew !== '') {
                $newExt = slugify($attrNew);
                /* Neither of the two names the size and flavour editors own, or
                   a custom axis would silently take over rows it did not write. */
                if ($newExt !== '' && $newExt !== VARIANT_SIZE_ATTR
                    && $newExt !== VARIANT_FLAVOR_ATTR) {
                    variants_attribute_upsert($id, $newExt, $attrNew, 10);
                }
            }

            foreach ((array) ($_POST['attr_drop'] ?? []) as $dropExt => $on) {
                if (!$on) { continue; }
                $dropExt = clean_text((string) $dropExt, 60);
                $aid = db_value('SELECT id FROM product_attributes
                                  WHERE product_id = ? AND ext_id = ?', [$id, $dropExt]);
                if ($aid === null) { continue; }
                db_query('DELETE FROM product_attribute_values WHERE attribute_id = ?',
                         [(int) $aid]);
                db_query('DELETE FROM product_attributes WHERE id = ?', [(int) $aid]);
            }

            $attrKeys  = (array) ($_POST['attr_key'] ?? []);
            $attrNames = (array) ($_POST['attr_name'] ?? []);
            foreach ($attrKeys as $ai => $extId) {
                $extId = clean_text((string) $extId, 60);
                if ($extId === '') { continue; }
                if (!empty($_POST['attr_drop'][$extId])) { continue; }

                $aid = db_value('SELECT id FROM product_attributes
                                  WHERE product_id = ? AND ext_id = ?', [$id, $extId]);
                if ($aid === null) { continue; }
                $aid = (int) $aid;

                $name = clean_text((string) ($attrNames[$ai] ?? ''), 120);
                if ($name !== '') {
                    db_query('UPDATE product_attributes SET name = ? WHERE id = ?', [$name, $aid]);
                }

                /* Values are upserted rather than replaced. A value's id is what
                   the variants point at, so rebuilding the list wholesale would
                   orphan every combination that used it - the grid would empty
                   itself every time somebody fixed a spelling. */
                $labels = (array) ($_POST['attr_val_label'][$extId] ?? []);
                $ids    = (array) ($_POST['attr_val_id'][$extId] ?? []);
                $files  = $_FILES['attr_val_img_' . $extId] ?? null;

                $keptValues = [];
                foreach ($labels as $vi => $label) {
                    $label = clean_text((string) $label, 120);
                    if ($label === '') { continue; }
                    $valExt = clean_text((string) ($ids[$vi] ?? ''), 60);
                    if ($valExt === '') { $valExt = slugify($label); }
                    if ($valExt === '') { continue; }

                    $img = '';
                    if (is_array($files) && isset($files['error'][$vi])
                        && $files['error'][$vi] !== UPLOAD_ERR_NO_FILE) {
                        $up = image_store([
                            'name'     => $files['name'][$vi],
                            'type'     => $files['type'][$vi],
                            'tmp_name' => $files['tmp_name'][$vi],
                            'error'    => $files['error'][$vi],
                            'size'     => $files['size'][$vi],
                        ]);
                        if ($up['ok']) { $img = $up['path']; }
                        else { flash('error', $label . ' — ' . $up['error']); }
                    }

                    /* An empty $img means «no file was chosen», and the upsert
                       keeps whatever picture the value already had. */
                    $keptValues[] = variants_value_upsert($aid, $valExt, $label, $img, $vi);
                }

                /* A row the operator removed from the form. Dropped after the
                   upserts so the ones that stayed keep their ids. */
                $gone = db_all('SELECT id FROM product_attribute_values WHERE attribute_id = ?',
                               [$aid]);
                foreach ($gone as $g) {
                    if (!in_array((int) $g['id'], $keptValues, true)) {
                        db_query('DELETE FROM product_attribute_values WHERE id = ?',
                                 [(int) $g['id']]);
                    }
                }
            }

            /* Mirror size and flavour into the attribute tables, fill in any
               combination the axes now imply, and drop the ones that lost a
               value. In that order: a variant whose value was just deleted has
               to go before the grid is drawn, or a stale cart could still
               resolve to a flavour the shop stopped stocking. */
            variants_adopt($id);
            variants_generate($id);
            variants_prune($id);

            /* --- the combinations grid ---------------------------------------

               Last, because the three calls above decide which rows exist. A
               price written before them could land on a row that is pruned a
               moment later, and a row generated by them would never see the
               figure the operator just typed. */
            $gExt    = (array) ($_POST['variant_ext'] ?? []);
            $gPrice  = (array) ($_POST['variant_price'] ?? []);
            $gCost   = (array) ($_POST['variant_cost'] ?? []);
            $gStock  = (array) ($_POST['variant_stock'] ?? []);
            $gActive = (array) ($_POST['variant_active'] ?? []);

            foreach ($gExt as $gi => $extRaw) {
                $extRaw = trim((string) $extRaw);
                if ($extRaw === '') { continue; }

                /* Matched on the key, not on $gi. The grid posts back in render
                   order, but renaming a value in the block above reorders it -
                   and by index the 900g price would land on the 2270g row. */
                $gRow = db_one('SELECT id FROM product_variants
                                 WHERE product_id = ? AND ext_id = ?', [$id, $extRaw]);
                if ($gRow === null) { continue; }
                $gvId = (int) $gRow['id'];

                $priceIn = (int) preg_replace('/\D/', '',
                    to_latin_digits((string) ($gPrice[$gi] ?? '0')));

                /* Empty is «not counted», which is not zero. The schema draws
                   that distinction everywhere else and the grid says so in
                   its own placeholder. */
                $stockRaw = trim(to_latin_digits((string) ($gStock[$gi] ?? '')));
                $stockIn  = $stockRaw === '' ? null
                    : max(0, (int) preg_replace('/\D/', '', $stockRaw));

                $costRaw = trim(to_latin_digits((string) ($gCost[$gi] ?? '')));
                $costRaw = str_replace(',', '', $costRaw);

                $gSets = ['stock_qty = ?', 'is_active = ?'];
                $gVals = [$stockIn, empty($gActive[$extRaw]) ? 0 : 1];
                if ($priceMode === 'aed') {
                    /* Blank is «what its size - or the product - costs, plus
                       its flavour's premium», worked out by
                       pricing_reprice_product() below; the grid draws such a
                       row blank, so a new purchase price typed on the size
                       reaches every combination of it. A column drawn in toman
                       is not a dirham figure: those rows go back to following
                       their size too. */
                    $gSets[] = 'cost_aed = ?';
                    $gVals[] = $gridUnitOk
                        ? (pricing_parse_aed((string) ($gCost[$gi] ?? '')) ?: null)
                        : null;
                    /* With a rate on record the price is the formula's. */
                    if (!$autoPrice) {
                        $gSets[] = 'price = ?';
                        $gVals[] = $priceIn;
                    }
                } else {
                    /* Figures typed as dirhams are not a toman cost; the
                       column keeps what it had. */
                    if ($gridUnitOk) {
                        $gSets[] = 'cost_toman = ?';
                        $gVals[] = $costRaw === '' ? null : (int) $costRaw;
                    }
                    $gSets[] = 'price = ?';
                    $gVals[] = $priceIn;
                }
                $gVals[] = $gvId;
                db_query('UPDATE product_variants SET ' . implode(', ', $gSets) . ' WHERE id = ?',
                         $gVals);

                /* Keyed by the row's id rather than by an array index: an
                   untouched file input posts nothing, so a positional name
                   would shift every later upload onto the wrong combination. */
                $gFile = $_FILES['variant_image_' . $gvId] ?? null;
                if (is_array($gFile) && ($gFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $gUp = image_store($gFile);
                    if ($gUp['ok']) {
                        db_query('UPDATE product_variants SET image = ? WHERE id = ?',
                                 [$gUp['path'], $gvId]);
                    } else {
                        flash('error', $extRaw . ' — ' . $gUp['error']);
                    }
                }
            }

            /* Every unit of a dirham product, priced at the rate the shelf is
               on. Last, once the sizes, the flavours and the combinations are
               all written, because every one of them is an input. */
            if ($autoPrice) {
                $priced = pricing_reprice_product($id, (float) $rateNow);
            }

            /* A commission change moves money between two parties, so it is
               not an ordinary edit and does not belong only in the row it
               changed. Logged append-only, with who and when. */
            $before = $previousComm === null ? null : (float) $previousComm;
            $after  = $commissionOverride;
            if ($before !== $after) {
                db_query(
                    'INSERT INTO commission_log
                        (scope, scope_id, label, old_percent, new_percent, admin_id, admin_name)
                     VALUES ("product", ?, ?, ?, ?, ?, ?)',
                    [$id, $nameFa, $before, $after, $user['id'], $user['name']]
                );
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('[suppex] product save failed: ' . $ex->getMessage());
            $errors['_'] = 'ذخیره محصول با خطا مواجه شد.';
        }

        /* --- Image order ----------------------------------------------------
           «مدیریت تصاویر» posts order[] - the image ids as the owner arranged
           them - through THIS form, so the page's ordinary save keeps it. A
           separate button alone would lose the order to anybody who arranged
           the photos and then pressed «ذخیره» at the bottom, which is what
           everybody does. Before the uploads, so new photos land after the
           arranged ones.

           Only this product's own images count, each once, whatever else is
           posted; one that is missing from the post keeps its place after the
           rest instead of being dropped. The first becomes the main image,
           because first is what main means on the product page. */
        if (!$errors && !$isNew && isset($_POST['order']) && is_array($_POST['order'])) {
            $ownPaths = [];
            foreach (db_all('SELECT id, path FROM product_images
                              WHERE product_id = ? ORDER BY sort_order, id', [$id]) as $o) {
                $ownPaths[(int) $o['id']] = (string) $o['path'];
            }
            $arranged = [];
            foreach ($_POST['order'] as $raw) {
                $iid = (int) $raw;
                if (isset($ownPaths[$iid]) && !in_array($iid, $arranged, true)) { $arranged[] = $iid; }
            }
            foreach (array_keys($ownPaths) as $iid) {
                if (!in_array($iid, $arranged, true)) { $arranged[] = $iid; }
            }
            foreach ($arranged as $pos => $iid) {
                db_query('UPDATE product_images SET sort_order = ? WHERE id = ?', [$pos, $iid]);
            }
            if ($arranged) {
                db_query('UPDATE products SET image = ? WHERE id = ?', [$ownPaths[$arranged[0]], $id]);
            }
        }

        /* --- Uploads --------------------------------------------------------
           After the product exists, so a new product's first images have an id
           to attach to. Each file is reported on individually: a rejected
           15 MB photo must not silently disappear among four that worked. */
        if (!$errors && !empty($_FILES['images']['name'][0])) {
            $count = count($_FILES['images']['name']);
            $sort  = (int) db_value(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?',
                [$id]
            );

            for ($i = 0; $i < min($count, 8); $i++) {
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'type'     => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE) { continue; }

                $stored = image_store($file);
                if ($stored['ok']) {
                    db_query(
                        'INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)',
                        [$id, $stored['path'], $sort++]
                    );
                    /* An empty main image, or one of the bundled .svg renders:
                       a real photograph beats a placeholder, the same line
                       admin/images.php and the readiness page draw. */
                    $curMain = (string) db_value('SELECT image FROM products WHERE id = ?', [$id]);
                    if ($curMain === '' || str_ends_with(strtolower($curMain), '.svg')) {
                        db_query('UPDATE products SET image = ? WHERE id = ?', [$stored['path'], $id]);
                    }
                } else {
                    flash('error', $file['name'] . ' — ' . $stored['error']);
                }
            }
        }

        if (!$errors) {
            flash('ok', $isNew ? 'محصول ساخته شد.' : 'تغییرات ذخیره شد.');
            /* With the number, because the number is what the operator came
               for: a cost and a profit went in, and this is what the shop now
               charges for them. */
            if (!empty($priced['price'])) {
                flash('info', 'قیمت فروش با نرخ ' . number_format((float) $rateNow) . ' تومان: '
                    . number_format($priced['price']) . ' تومان'
                    . (!empty($priced['compare_at'])
                        ? '، قبل از تخفیف ' . number_format($priced['compare_at']) . ' تومان'
                        : '')
                    . '.');
            }
            if ($autoPrice && pricing_manual_ready() && $compareAt !== null && $compareAt > 0
                && !empty($priced['price']) && $compareAt <= $priced['price']) {
                flash('info', 'قیمت قبل از تخفیف (' . number_format($compareAt) . ' تومان) از قیمت فروش ('
                    . number_format($priced['price']) . ' تومان) بیشتر نیست، پس خط‌خورده نمایش داده '
                    . 'نمی‌شود. عددش نگه داشته شده و اگر قیمت فروش پایین‌تر از آن بیاید، دیده می‌شود.');
            }
            if (!empty($priced['capped'])) {
                flash('info', 'تخفیف از نصف سود بیشتر بود؛ فقط تا نصف سود اعمال شد تا فروش بی‌سود نشود.');
            }
            if ($droppedPremium) {
                flash('info', 'اضافه‌بهای طعم‌ها به واحد قبلی وارد شده بود و با عوض شدن حالت '
                    . 'قیمت‌گذاری کنار گذاشته شد. اگر طعمی گران‌تر است، اضافه‌بهایش را دوباره وارد کنید.');
            }
            header('Location: product-edit.php?id=' . $id);
            exit;
        }
    }

    /* Validation failed: re-render with what was typed, so nothing is retyped.

       array_merge only reaches the scalar columns, and it is not enough on its
       own: it also drops the raw textarea text into $product['features'], which
       the display block below json_decode_array()s into an empty array. So the
       flag, and the four rebuilds under "Load for display" - without them a
       refused save silently emptied the feature bullets and threw away every
       size, flavour and nutrition row the operator had just typed. */
    $product  = array_merge($product ?? [], $_POST);
    $refused  = true;
}

/* --------------------------------------------------------------------------
   Load for display
   -------------------------------------------------------------------------- */
$categories = db_all('SELECT id, name_fa FROM categories ORDER BY sort_order, id');
$images     = $isNew ? [] : db_all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]);
/* Main first, the order product_get() gives the product page, so what the
   owner arranges here is exactly what a customer sees. */
if ($images && $product) {
    foreach ($images as $k => $img) {
        if ($k > 0 && $img['path'] === (string) ($product['image'] ?? '')) {
            unset($images[$k]);
            array_unshift($images, $img);
            break;
        }
    }
    $images = array_values($images);
}
$flavors    = $isNew ? [] : db_all('SELECT * FROM product_flavors WHERE product_id = ? ORDER BY sort_order, id', [$id]);
$sizes      = $isNew ? [] : db_all('SELECT * FROM product_sizes  WHERE product_id = ? ORDER BY sort_order, id', [$id]);

/* The rate the shelf is currently priced from, handed to the page so a dirham
   figure can be read in toman as it is typed. Null until somebody has applied
   a rate, in which case there is nothing to convert with and the block below
   stays silent rather than showing zeros. */
$aedRateNow = pricing_rate();
$nutrition  = json_decode_array($product['nutrition_rows'] ?? null);
$features   = $product ? json_decode_array($product['features'] ?? null) : [];

/* A refused save re-renders from what was typed, not from the database.

   Each of these is built in the exact shape its template reads, so the form
   comes back as the operator left it. Rows are kept even when incomplete - a
   half-typed size is what they were in the middle of, and dropping it while
   showing an error about a different field is how work disappears. */
if (!empty($refused)) {
    $features = lines_to_array($_POST['features'] ?? '');

    $nLabels = (array) ($_POST['nutrition_label'] ?? []);
    $nValues = (array) ($_POST['nutrition_value'] ?? []);
    $nutrition = [];
    foreach ($nLabels as $i => $lab) {
        $lab = clean_text((string) $lab, 120);
        $val = clean_text((string) ($nValues[$i] ?? ''), 120);
        if ($lab === '' && $val === '') { continue; }
        $nutrition[] = ['label' => $lab, 'value' => $val];
    }

    $fIds   = (array) ($_POST['flavor_id'] ?? []);
    $fNames = (array) ($_POST['flavor_name'] ?? []);
    $fExtraIn = (array) ($_POST['flavor_extra'] ?? []);
    $flavors = [];
    foreach ($fNames as $i => $name) {
        $name = clean_text((string) $name, 120);
        $ext  = clean_text((string) ($fIds[$i] ?? ''), 60);
        if ($name === '' && $ext === '') { continue; }
        /* Carried back into the redisplayed form so a validation error does
           not silently drop a premium the operator had just typed. Which
           column it lands in follows the product's mode, the same way the
           save does. */
        $raw = trim(to_latin_digits((string) ($fExtraIn[$i] ?? '')));
        /* In both columns. The cell is drawn in the unit the figure was
           TYPED in (flavor_unit, below), and after a change of mode that is
           not the mode being saved - keyed by the mode, the figure vanished
           from the page that was meant to give it back. */
        $flavors[] = [
            'ext_id'            => $ext,
            'name'              => $name,
            'cost_delta_aed'    => $raw !== '' ? $raw : null,
            'price_delta_toman' => $raw !== '' ? parse_money($raw) : 0,
        ];
    }

    $sIds     = (array) ($_POST['size_id'] ?? []);
    $sLabels  = (array) ($_POST['size_label'] ?? []);
    $sServ    = (array) ($_POST['size_servings'] ?? []);
    $sAed     = (array) ($_POST['size_cost_aed'] ?? []);
    $sToman   = (array) ($_POST['size_cost_toman'] ?? []);
    $sProfit  = (array) ($_POST['size_profit'] ?? []);
    $sPrice   = (array) ($_POST['size_price'] ?? []);
    $sCompare = (array) ($_POST['size_compare'] ?? []);
    $sizes = [];
    foreach ($sLabels as $i => $label) {
        $label = clean_text((string) $label, 120);
        $ext   = clean_text((string) ($sIds[$i] ?? ''), 60);
        if ($label === '' && $ext === '') { continue; }
        $sizes[] = [
            'ext_id'       => $ext,
            'label'        => $label,
            'servings'     => clean_text((string) ($sServ[$i] ?? ''), 20),
            'cost_aed'     => clean_text((string) ($sAed[$i] ?? ''), 20),
            'cost_toman'   => clean_text((string) ($sToman[$i] ?? ''), 20),
            'profit_toman' => clean_text((string) ($sProfit[$i] ?? ''), 20),
            'price'        => clean_text((string) ($sPrice[$i] ?? ''), 20),
            'compare_at'   => clean_text((string) ($sCompare[$i] ?? ''), 20),
        ];
    }
}

/** Field value, preferring what was just posted. */
function v(?array $product, string $key, $default = ''): string
{
    if ($product === null) { return (string) $default; }
    return (string) ($product[$key] ?? $default);
}

$badgeList = $product ? explode(',', (string) ($product['badges'] ?? '')) : [];

admin_head($isNew ? 'محصول جدید' : 'ویرایش: ' . v($product, 'name_fa'), ['user' => $user]);
?>

<?php if (!empty($errors['_'])): ?>
  <div class="flash flash--error"><?= e($errors['_']) ?></div>
<?php endif; ?>

<form id="product-form" method="post" action="product-edit.php<?= $isNew ? '' : '?id=' . $id ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <nav class="formnav" aria-label="بخش‌های این صفحه" data-formnav>
    <a href="#sec-info">اطلاعات</a>
    <a href="#sec-price">قیمت</a>
    <a href="#sec-display">نمایش</a>
    <a href="#sec-images">عکس‌ها</a>
    <a href="#sec-texts">توضیحات</a>
    <a href="#sec-options">طعم و اندازه</a>
    <a href="#sec-nutrition">ارزش غذایی</a>
  </nav>

  <div class="card" id="sec-info" data-formnav-target>
    <h2 class="card__title">اطلاعات محصول</h2>
    <div class="form-grid">
      <div class="field">
        <label for="name_fa">نام فارسی *</label>
        <input type="text" id="name_fa" name="name_fa" required value="<?= e(v($product, 'name_fa')) ?>">
        <?php if (!empty($errors['name_fa'])): ?><span class="err"><?= e($errors['name_fa']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="name_en">نام انگلیسی</label>
        <input type="text" id="name_en" name="name_en" dir="ltr" value="<?= e(v($product, 'name_en')) ?>">
        <span class="hint">در کارت محصول زیر نام فارسی نمایش داده می‌شود.</span>
      </div>

      <div class="field">
        <label for="brand">برند</label>
        <input type="text" id="brand" name="brand" dir="ltr" value="<?= e(v($product, 'brand')) ?>">
      </div>

      <div class="field">
        <label for="category_id">دسته</label>
        <select id="category_id" name="category_id">
          <option value="">بدون دسته</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
              <?= (string) v($product, 'category_id') === (string) $c['id'] ? ' selected' : '' ?>>
              <?= e($c['name_fa']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field field--wide">
        <label for="short">توضیح کوتاه</label>
        <textarea id="short" name="short" rows="2"><?= e(v($product, 'short')) ?></textarea>
        <span class="hint">یک جمله؛ در نتایج جستجو و بالای صفحه محصول دیده می‌شود.</span>
      </div>

      <div class="field">
        <label class="check">
          <input type="checkbox" name="in_stock" value="1"
            <?= ($isNew || (int) v($product, 'in_stock', '1') === 1) ? ' checked' : '' ?>>
          <span>موجود است</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="checkbox" name="is_active" value="1"
            <?= ($isNew || (int) v($product, 'is_active', '1') === 1) ? ' checked' : '' ?>>
          <span>در سایت نمایش داده شود</span>
        </label>
        <span class="hint" style="margin-block-start:8px">
          این کلید دستی است و بر همه چیز مقدم است.
          <?php if (!$isNew): ?>
            برای شمردن تعداد،
            <a href="stock.php">موجودی انبار</a>.
          <?php endif; ?>
        </span>
      </div>

    </div>
  </div>

  <?php /* The mode first: it decides which of the figures below are typed
           and which the formula fills in. */ ?>
  <div class="card" id="sec-price" data-formnav-target>
    <h2 class="card__title">قیمت</h2>
    <div class="form-grid">
      <?php
      /* In dirham mode, with a rate on record, these two are the formula's
         answer rather than something to type: read-only, and filled in by the
         price script at the foot of the page as the purchase price and profit
         are typed. The save works them out again for itself, so nothing that
         arrives from here is trusted. The script also flips all of this when
         the mode is switched without saving. */
      $isAedNow = v($product, 'price_mode', 'manual') === 'aed';
      $autoNow  = $isAedNow && $aedRateNow !== null;
      ?>
      <div class="field field--wide">
        <label>حالت قیمت‌گذاری</label>
        <label class="check">
          <input type="radio" name="price_mode" value="manual"
            <?= v($product, 'price_mode', 'manual') !== 'aed' ? ' checked' : '' ?>>
          <span>قیمت را دستی وارد می‌کنم</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="radio" name="price_mode" value="aed"
            <?= v($product, 'price_mode', 'manual') === 'aed' ? ' checked' : '' ?>>
          <span>بر اساس نرخ درهم حساب شود</span>
        </label>
        <span class="hint">
          با انتخاب حالت درهمی، قیمت این محصول از فرمول
          <span class="num">(خرید به درهم × نرخ) + سود − تخفیف</span>
          ساخته می‌شود: همین حالا با نرخ فعلی، و بعد در صفحه
          <a href="pricing.php" style="text-decoration:underline">قیمت‌گذاری</a>
          با هر تغییر نرخ. قیمت بالا دیگر دستی ویرایش نمی‌شود.
          <?php if ($aedRateNow !== null): ?>
            نرخ فعلی: <b class="num"><?= e(number_format($aedRateNow)) ?></b> تومان.
          <?php endif; ?>
        </span>
      </div>

      <div class="field">
        <label for="price">قیمت (تومان) *
          <span class="auto-tag" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>خودکار</span></label>
        <input type="text" id="price" name="price" inputmode="numeric" required
               data-auto-price<?= $autoNow ? ' readonly' : '' ?>
               value="<?= e(v($product, 'price')) ?>">
        <span class="hint" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>
          از قیمت خرید و سود حساب می‌شود — پایین‌تر، «قیمت فروش».
        </span>
        <?php if (!empty($errors['price'])): ?><span class="err"><?= e($errors['price']) ?></span><?php endif; ?>
      </div>

      <?php
      /* In dirham mode the struck price is typed by hand too, once the column
         for it exists; it is kept in compare_manual, because compare_at is
         rewritten at every repricing. Before the migration it stays the
         discount's, locked like the price. */
      $manualCmp = pricing_manual_ready();
      $cmpLocked = $autoNow && !$manualCmp;
      $cmpValue  = v($product, 'compare_at');
      if ($isAedNow && $manualCmp && empty($refused)) {
          $cmpValue = (string) ($product['compare_manual'] ?? '');
      }
      ?>
      <div class="field">
        <label for="compare_at">قیمت قبل از تخفیف
          <?php if (!$manualCmp): ?>
            <span class="auto-tag" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>خودکار</span>
          <?php endif; ?></label>
        <input type="text" id="compare_at" name="compare_at" inputmode="numeric"
               data-auto-compare<?= $cmpLocked ? ' readonly' : '' ?>
               value="<?= e($cmpValue) ?>">
        <span class="hint" data-manual-only<?= $autoNow ? ' hidden' : '' ?>>خالی بگذارید تا محصول بدون تخفیف نمایش داده شود.</span>
        <span class="hint" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>
          <?php if ($manualCmp): ?>
            اختیاری. عددی که بنویسید خط‌خورده کنار قیمت فروش دیده می‌شود. ثابت است
            و با نرخ حرکت نمی‌کند؛ اگر قیمت فروش به آن برسد، دیگر نمایش داده نمی‌شود.
            خالی بگذارید تا از «تخفیف» پایین ساخته شود.
          <?php else: ?>
            از «تخفیف» پایین ساخته می‌شود؛ بدون تخفیف، خالی می‌ماند. برای نوشتن دستی
            آن، یک بار <a href="migrate.php" style="text-decoration:underline">به‌روزرسانی پایگاه داده</a>
            را اجرا کنید.
          <?php endif; ?>
        </span>
        <?php if (!empty($errors['compare_at'])): ?><span class="err"><?= e($errors['compare_at']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="cost_aed">قیمت خرید (درهم)</label>
        <input type="text" id="cost_aed" name="cost_aed" dir="ltr" inputmode="decimal"
               data-aed placeholder="42.50" value="<?= e(v($product, 'cost_aed')) ?>">
        <span class="hint" data-aed-out></span>
        <span class="hint">اعشار هم می‌پذیرد. همان عددی که در دبی پرداخت کرده‌اید.</span>
        <?php if (!empty($errors['cost_aed'])): ?><span class="err"><?= e($errors['cost_aed']) ?></span><?php endif; ?>
      </div>

      <?php /* For a dirham product this is purchase × rate, and the save
               ignores whatever is typed here (see 'cost_toman' in the save) -
               so in that mode it is locked and filled in like the price. */ ?>
      <div class="field">
        <label for="cost_toman">قیمت خرید به تومان
          <span class="auto-tag" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>خودکار</span></label>
        <input type="text" id="cost_toman" name="cost_toman" inputmode="numeric"
               data-auto-cost<?= $autoNow ? ' readonly' : '' ?>
               placeholder="650000" value="<?= e(v($product, 'cost_toman')) ?>">
        <span class="hint" data-manual-only<?= $autoNow ? ' hidden' : '' ?>>
          فقط برای قلمی که داخل ایران خریده می‌شود. اگر خرید
          درهمی است خالی بگذارید — خودش از نرخ روز حساب می‌شود.
          بدون قیمت خرید، سود این قلم در گزارش‌ها صفر دیده می‌شود.
        </span>
        <span class="hint" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>
          در حالت درهمی خودکار است: قیمت خرید درهمی × نرخ روز.
        </span>
      </div>

      <div class="field">
        <label for="profit_toman">سود شما روی این محصول (تومان)</label>
        <input type="text" id="profit_toman" name="profit_toman" inputmode="numeric"
               placeholder="1750000" value="<?= e(v($product, 'profit_toman')) ?>">
        <span class="hint">
          سودی که می‌خواهید روی هر عدد داشته باشید. هزینه حمل و ترخیص را
          هم در همین عدد یا در قیمت خرید حساب کنید.
        </span>
        <?php
        /* The ratio this profit implies, shown because it — not the toman
           figure — is what survives a change in the dirham. Both sides of it
           are printed: «درصد روی قیمت خرید» is what is stored, «حاشیه» is the
           share of the selling price, which is the number a shopkeeper means
           by "what do I make on this". They are the same fact. */
        $storedPct = $product['profit_percent'] ?? null;
        if ($storedPct !== null && (float) $storedPct > 0):
            $pctF = (float) $storedPct;
        ?>
          <span class="hint" style="color:var(--success)">
            درصد سود ثبت‌شده: <b class="num"><?= e(number_format($pctF, 1)) ?>٪</b>
            روی قیمت خرید — یعنی حاشیه
            <b class="num"><?= e(number_format($pctF / (1 + $pctF / 100), 1)) ?>٪</b>
            از قیمت فروش.
            <br>
            وقتی نرخ درهم بالا برود همین درصد حفظ می‌شود و مبلغ تومانی سود
            خودش بالا می‌رود. اگر عدد بالا را عوض کنید، درصد دوباره از روی
            نرخ امروز حساب می‌شود.
          </span>
        <?php elseif (($product['price_mode'] ?? '') === 'aed' && $sizes): ?>
          <span class="hint">
            این محصول اندازه دارد: اندازه‌ای که سود خودش را دارد با همان
            حساب می‌شود، و اندازه‌ای که ستون سودش خالی است با همین عدد —
            پایین‌تر، در بخش «اندازه‌ها».
          </span>
        <?php elseif (($product['price_mode'] ?? '') === 'aed'): ?>
          <span class="hint">
            درصد سود هنوز ثبت نشده — با اولین اعمال نرخ در صفحه قیمت‌گذاری
            قفل می‌شود.
          </span>
        <?php endif; ?>
        <?php if (!empty($errors['profit_toman'])): ?><span class="err"><?= e($errors['profit_toman']) ?></span><?php endif; ?>
      </div>

      <?php /* The discount as an amount off, which is what the pricing page
               and the import sheet already store (promo_toman): the full
               price is struck through and this much less is charged. In
               dirham mode only - a manual product types its struck price
               directly, above. */ ?>
      <div class="field" data-aed-only<?= $isAedNow ? '' : ' hidden' ?>>
        <label for="promo_toman">تخفیف (تومان)</label>
        <input type="text" id="promo_toman" name="promo_toman" inputmode="numeric"
               placeholder="بدون تخفیف" value="<?= e(v($product, 'promo_toman')) ?>">
        <span class="hint">
          اختیاری. همین مبلغ از قیمت کم می‌شود و قیمت کامل، خط‌خورده، کنارش
          دیده می‌شود. بیشتر از نصف سود اعمال نمی‌شود.
        </span>
      </div>

      <?php /* The answer, next to the figures it comes from, with the working:
               the operator asked for the price to be computed because it is
               more exact, so the page shows exactly how. Filled in by the
               price script at the foot of the page. */ ?>
      <div class="field field--wide auto-box" data-aed-only<?= $isAedNow ? '' : ' hidden' ?>>
        <?php if ($aedRateNow === null): ?>
          <p class="auto-box__none">
            نرخ درهم هنوز ثبت نشده، پس فعلاً قیمت را بالا دستی وارد کنید.
            با اولین ثبت نرخ در صفحه <a href="pricing.php" style="text-decoration:underline">قیمت‌گذاری</a>
            از همین فرمول حساب می‌شود.
          </p>
        <?php else: ?>
          <div class="auto-box__head">
            <span data-sum-title>قیمت فروش</span>
            <b class="num" data-sum-price>—</b>
            <span class="auto-box__unit">تومان</span>
            <s class="num" data-sum-compare hidden></s>
          </div>
          <dl class="auto-box__lines" data-sum-lines></dl>
          <p class="hint" data-sum-note></p>
        <?php endif; ?>
      </div>

    </div>
    <details class="disclosure"<?= (!empty($errors['commission_percent']) || v($product, 'commission_percent') !== '') ? ' open' : '' ?>>
      <summary>سهم همکاری این محصول (اختیاری)</summary>
      <div class="form-grid">
      <div class="field">
        <label for="commission_percent">درصد سهم همکاری (اختیاری)</label>
        <input type="text" id="commission_percent" name="commission_percent" dir="ltr"
               inputmode="decimal" placeholder="پیش‌فرض فروشگاه"
               value="<?= e(v($product, 'commission_percent')) ?>">
        <span class="hint">
          <strong>خالی بگذارید</strong> مگر اینکه این محصول عمداً استثنا باشد.
          چون سهم روی <em>سود</em> حساب می‌شود، محصول کم‌حاشیه خودش سهم کمتری می‌دهد
          و لازم نیست درصدش را دستی پایین بیاورید.
          <br>
          <span class="num">0</span> یعنی این محصول اصلاً سهمی ندارد —
          با خالی بودن فرق دارد. هر تغییر این عدد در تاریخچه ثبت می‌شود.
        </span>
        <?php if (!empty($errors['commission_percent'])): ?>
          <span class="err"><?= e($errors['commission_percent']) ?></span>
        <?php endif; ?>
      </div>

      </div>
    </details>
  </div>

  <div class="card" id="sec-display" data-formnav-target>
    <h2 class="card__title">نمایش در فروشگاه</h2>
    <div class="form-grid">
      <div class="field">
        <label>نشان‌ها</label>
        <label class="check">
          <input type="checkbox" name="badge_bestseller" value="1"
            <?= in_array('bestseller', $badgeList, true) ? ' checked' : '' ?>>
          <span>پرفروش</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="checkbox" name="badge_new" value="1"
            <?= in_array('new', $badgeList, true) ? ' checked' : '' ?>>
          <span>جدید</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="checkbox" name="badge_topdeal" value="1"
            <?= in_array('topdeal', $badgeList, true) ? ' checked' : '' ?>>
          <span>تخفیف برتر</span>
        </label>
        <span class="hint">
          «تخفیف برتر» یعنی این محصول با عکس و قیمتش بالای صفحهٔ اصلی نمایش
          داده شود. حداکثر سه محصول آنجا جا می‌شود؛ اگر بیشتر تیک بزنید، سه‌تایی
          که بیشترین تخفیف را دارند نشان داده می‌شوند، و محصولِ ناموجود نشان
          داده نمی‌شود. برای اینکه قیمتِ خط‌خورده و درصد تخفیف هم کنارش بیاید،
          «قیمت قبل از تخفیف» را هم پر کنید. اگر هیچ محصولی تیک نخورده باشد،
          سه محصولِ موجود با بیشترین تخفیف خودکار نمایش داده می‌شوند.
        </span>
      </div>

      <div class="field">
        <label for="sort_order">ترتیب نمایش</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= e(v($product, 'sort_order', '0')) ?>">
        <span class="hint">عدد کوچک‌تر، بالاتر.</span>
      </div>
    </div>
    <details class="disclosure"<?= v($product, 'variant_label') !== '' ? ' open' : '' ?>>
      <summary>آدرس صفحه و برچسب کوتاه</summary>
      <div class="form-grid">
      <div class="field">
        <label for="variant_label">برچسب کوتاه</label>
        <input type="text" id="variant_label" name="variant_label"
               placeholder="مثلاً: وانیل · 900 گرم" value="<?= e(v($product, 'variant_label')) ?>">
        <span class="hint">زیر نام محصول در کارت دیده می‌شود.</span>
      </div>

      <div class="field">
        <label for="slug">آدرس صفحه (slug)</label>
        <input type="text" id="slug" name="slug" dir="ltr" value="<?= e(v($product, 'slug')) ?>">
        <span class="hint">
          خالی بگذارید تا خودکار از نام ساخته شود.
          تغییر آن، لینک‌هایی که قبلاً از این محصول به اشتراک گذاشته شده را می‌شکند.
        </span>
      </div>

      </div>
    </details>
  </div>

  <div class="card" id="sec-images" data-formnav-target>
    <h2 class="card__title">عکس‌ها</h2>

    <?php if ($isNew): ?>
      <p class="u-dim" style="margin-block-end:12px">
        بعد از ذخیره اولیه می‌توانید تصاویر بیشتری اضافه یا حذف کنید.
      </p>
    <?php elseif ($images): ?>
    <!-- One order for everything: the order here is the order on the product
         page, and the first image is the main one - on the cards, in the
         hero and in a shared link's preview. «اصلی کن» used to set the main
         image without moving it, so the page and the cards disagreed about
         which photo came first; moving a photo to the front does both.

         This grid used to be a second box below the form, because each tile
         carried its own delete form and forms do not nest. The delete forms
         now sit after the product form and each × names its own (form=…),
         so the grid lives here, beside the upload, inside the product form -
         its order[] inputs still say form="product-form", which is true and
         harmless. Their submitted order is their order in the document, which
         is why moving a tile moves its input with it. -->
    <p class="hint" style="margin-block-end:4px">
      ترتیب این‌جا همان ترتیبی است که تصاویر در صفحهٔ محصول نمایش داده می‌شوند، و
      تصویر اول، تصویر اصلی است (روی کارت محصول و در پیش‌نمایش لینک هم همان می‌آید).
      برای جابه‌جایی، تصویر را بکشید و رها کنید یا از فلش‌ها استفاده کنید؛ بعد ذخیره کنید.
    </p>
    <div class="gallery gallery--sort" data-img-sort>
      <?php foreach ($images as $n => $img): ?>
        <div class="gallery__item" draggable="true" data-img-item>
          <img src="<?= e(admin_img($img['path'])) ?>" alt="" loading="lazy" draggable="false">
          <input type="hidden" name="order[]" value="<?= (int) $img['id'] ?>" form="product-form">
          <span class="gallery__pos num" data-img-pos aria-hidden="true"><?= fa_digits((string) ($n + 1)) ?></span>
          <button class="gallery__del" type="submit" form="del-img-<?= (int) $img['id'] ?>"
                  data-confirm="این تصویر حذف شود؟" aria-label="حذف تصویر">×</button>
          <span class="gallery__main" data-img-main<?= $n === 0 ? '' : ' hidden' ?>>اصلی</span>
          <div class="gallery__move">
            <button class="gallery__arrow" type="button" data-move="-1"
                    aria-label="جابه‌جایی به سمت اول"<?= $n === 0 ? ' disabled' : '' ?>>&rarr;</button>
            <button class="gallery__arrow" type="button" data-move="1"
                    aria-label="جابه‌جایی به سمت آخر"<?= $n === count($images) - 1 ? ' disabled' : '' ?>>&larr;</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="gallery__actions">
      <?php /* A plain button that saves the form, not a submit button of its
               own: inside the form, a disabled submit button ahead of «ذخیره»
               would become the one Enter presses, and Enter would stop saving. */ ?>
      <button class="btn btn--primary btn--sm" type="button" data-img-save disabled>ذخیرهٔ ترتیب</button>
      <span class="hint" data-img-dirty hidden>ترتیب تغییر کرده و هنوز ذخیره نشده.</span>
      <span class="sr-only" data-img-say aria-live="polite"></span>
    </div>
    <?php endif; ?>

    <div class="field" style="margin-block-start:16px">
      <label for="images">افزودن عکس</label>
      <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
      <span class="hint">
        JPG، PNG یا WebP — حداکثر ۶ مگابایت برای هر تصویر، تا ۸ تصویر در هر بار.
        تصاویر خودکار به حداکثر ۱۴۰۰ پیکسل کوچک و فشرده می‌شوند.
      </span>
    </div>
  </div>

  <div class="card" id="sec-texts" data-formnav-target>
    <h2 class="card__title">توضیحات</h2>
    <div class="form-grid">
      <div class="field field--wide">
        <label for="description">توضیح کامل</label>
        <textarea id="description" name="description" rows="6"><?= e(v($product, 'description')) ?></textarea>
      </div>
      <div class="field field--wide">
        <label for="features">ویژگی‌ها</label>
        <textarea id="features" name="features" rows="5"><?= e(array_to_lines($features)) ?></textarea>
        <span class="hint">هر ویژگی در یک خط جدا.</span>
      </div>
      <div class="field field--wide">
        <label for="ingredients">ترکیبات</label>
        <textarea id="ingredients" name="ingredients" rows="3"><?= e(v($product, 'ingredients')) ?></textarea>
      </div>
      <div class="field field--wide">
        <label for="usage_text">روش مصرف</label>
        <textarea id="usage_text" name="usage_text" rows="3"><?= e(v($product, 'usage_text')) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card" id="sec-options" data-formnav-target>
    <h2 class="card__title">طعم، اندازه و ویژگی‌ها</h2>
    <p class="hint" style="margin-block-end:18px">
      هر چیزی که مشتری باید انتخاب کند همین‌جاست: طعم، اندازه، و هر
      ویژگی دیگری که خودتان اضافه کنید.
    </p>

    <h3 class="attr__head">طعم</h3>
    <div class="rows" data-repeat="flavor">
      <?php
      $aedMode = ($product['price_mode'] ?? 'manual') === 'aed';
      /* The unit the premium column is drawn in, posted back so the save can
         tell a dirham premium from a toman one. After a refused save it is
         the unit the figures were typed in, which a change of mode makes
         different from the mode being saved. */
      $flavorUnit = !empty($refused)
          ? ((string) ($_POST['flavor_unit'] ?? ($aedMode ? 'aed' : 'toman')) === 'aed' ? 'aed' : 'toman')
          : ($aedMode ? 'aed' : 'toman');
      $fAed = $flavorUnit === 'aed';
      ?>
      <input type="hidden" name="flavor_unit" value="<?= e($flavorUnit) ?>">
      <div class="rows__row rows__row--flavor rows__head">
        <span>شناسه (اختیاری)</span><span>نام طعم</span>
        <span>اضافه‌بها (<span data-flavor-unit-label><?= $fAed ? 'درهم' : 'تومان' ?></span>)</span>
        <span>تصویر طعم</span><span></span>
      </div>
      <?php
      $flavorRows = $flavors ?: [['ext_id' => '', 'name' => '']];
      /* An unticked checkbox sends nothing at all, so flavor_image_clear[]
         would renumber - row 3's tick arriving as row 0's. Indexed by hand so
         each answer stays attached to the row that gave it. */
      $fLoopIndex = -1;
      foreach ($flavorRows as $f): $fLoopIndex++; ?>
        <?php
        $fExtraVal = '';
        if ($fAed) {
            $fExtraVal = ($f['cost_delta_aed'] ?? null) === null
                ? '' : (string) (pricing_parse_aed((string) $f['cost_delta_aed']) ?? '');
        } elseif ((int) ($f['price_delta_toman'] ?? 0) > 0) {
            $fExtraVal = (string) (int) $f['price_delta_toman'];
        }
        ?>
        <div class="rows__row rows__row--flavor" data-row>
          <input type="text" name="flavor_id[]" dir="ltr" placeholder="vanilla" value="<?= e($f['ext_id']) ?>">
          <input type="text" name="flavor_name[]" placeholder="وانیل" value="<?= e($f['name']) ?>">
          <div class="aed-cell">
            <input type="text" name="flavor_extra[]" dir="ltr"
                   inputmode="<?= $fAed ? 'decimal' : 'numeric' ?>"
                   <?= $fAed ? 'data-aed' : '' ?>
                   placeholder="<?= $fAed ? '2.50' : '150000' ?>" value="<?= e($fExtraVal) ?>">
            <?php if ($fAed): ?><span class="hint" data-aed-out></span><?php endif; ?>
          </div>
          <?php /* The rows are rebuilt on every save, so there is no hidden field
                   carrying the current path back - the save reads it from the
                   database by name instead. This cell only shows what is there
                   and offers the two ways to change it. */ ?>
          <div class="flavor-img">
            <?php $fImg = trim((string) ($f['image'] ?? '')); ?>
            <?php if ($fImg !== ''): ?>
              <img class="flavor-img__thumb" src="<?= e(admin_img($fImg)) ?>" alt="" width="38" height="38">
            <?php endif; ?>
            <input type="file" name="flavor_image[]" accept="image/*">
            <?php if ($fImg !== ''): ?>
              <label class="flavor-img__clear">
                <input type="checkbox" name="flavor_image_clear[<?= (int) $fLoopIndex ?>]" value="1"> حذف
              </label>
            <?php endif; ?>
          </div>
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <?php /* Shown by the price script when the mode has been switched on this
             page while the premium column still holds figures typed in the
             other currency - which the save will not read as this one. */ ?>
    <p class="hint aed-out--big" data-flavor-unit-warn hidden>
      اضافه‌بهای طعم‌ها به واحد حالت قبلی وارد شده و با این ذخیره کنار گذاشته
      می‌شود. خانه‌های اضافه‌بها را خالی کنید تا ستون به واحد تازه برود و دوباره
      واردشان کنید.
    </p>
    <button class="btn btn--ghost btn--sm" type="button" data-add="flavor" style="margin-block-start:10px">
      افزودن طعم
    </button>
    <p class="hint" style="margin-block-start:10px">
      اضافه‌بها را فقط برای طعمی پر کنید که <strong>گران‌تر</strong> خریده‌اید.
      خالی یعنی همان قیمت بقیه — که حالت معمول است.
      <?php if ($aedMode): ?>
        چون خرید این محصول درهمی است، اضافه‌بها هم به درهم گرفته می‌شود و با
        نرخ روز حرکت می‌کند؛ همان درصد سود محصول روی آن هم اعمال می‌شود.
      <?php endif; ?>
      <br>
      این عدد به قیمت هر اندازه‌ای که مشتری انتخاب کند اضافه می‌شود، پس
      لازم نیست برای هر ترکیب اندازه و طعم قیمت جدا بگذارید.
    </p>

    <h3 class="attr__head">اندازه و قیمت هرکدام</h3>
    <div class="rows" data-repeat="size">
      <div class="rows__row rows__row--5 rows__head">
        <span>شناسه</span><span>عنوان</span><span>سروینگ</span>
        <span>خرید (درهم)</span><span>خرید (تومان)</span>
        <span>سود (تومان)</span>
        <span>قیمت فروش <span class="auto-tag" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>خودکار</span></span>
        <span></span>
      </div>
      <?php
      $sizeRows = $sizes ?: [['ext_id' => '', 'label' => '', 'servings' => '', 'price' => '',
                             'compare_at' => '', 'cost_aed' => '', 'cost_toman' => '',
                             'profit_toman' => '']];
      foreach ($sizeRows as $s): ?>
        <div class="rows__row rows__row--5" data-row>
          <input type="text" name="size_id[]" dir="ltr" placeholder="900" value="<?= e($s['ext_id']) ?>">
          <input type="text" name="size_label[]" placeholder="900 گرم" value="<?= e($s['label']) ?>">
          <input type="text" name="size_servings[]" inputmode="numeric" value="<?= e((string) $s['servings']) ?>">
          <?php /* Wrapped so the converted figure can sit under the field
                   rather than becoming a ninth column in the grid. */ ?>
          <div class="aed-cell">
            <input type="text" name="size_cost_aed[]" dir="ltr" inputmode="decimal"
                   data-aed placeholder="42.50" value="<?= e((string) ($s['cost_aed'] ?? '')) ?>">
            <span class="hint" data-aed-out></span>
          </div>
          <input type="text" name="size_cost_toman[]" inputmode="numeric"
                 placeholder="650000" value="<?= e((string) ($s['cost_toman'] ?? '')) ?>">
          <input type="text" name="size_profit[]" inputmode="numeric"
                 placeholder="1750000" value="<?= e((string) ($s['profit_toman'] ?? '')) ?>">
          <input type="text" name="size_price[]" inputmode="numeric"<?= $autoNow ? ' readonly' : '' ?>
                 value="<?= e((string) $s['price']) ?>">
          <input type="hidden" name="size_compare[]" value="<?= e((string) $s['compare_at']) ?>">
          <?php /* What this row was called when the page was rendered. The
                   visible شناسه is editable and the label can be renamed, so
                   the values this form has no input for - the commission rate,
                   the discount, the cost - are matched on this instead. A row
                   the operator adds by hand sends an empty one and correctly
                   inherits nothing. */ ?>
          <input type="hidden" name="size_prev_id[]" value="<?= e((string) $s['ext_id']) ?>">
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn--ghost btn--sm" type="button" data-add="size" style="margin-block-start:10px">
      افزودن اندازه
    </button>
    <?php
    /* The ratio each size is held to. Shown here rather than inside the row
       because the row is a fixed grid and a wrapped line would break it — and
       because the useful view is all the sizes together, where a ratio that is
       out of line with its neighbours is obvious at a glance. */
    $lockedSizes = [];
    foreach ($sizes as $sz) {
        $pc = $sz['profit_percent'] ?? null;
        if ($pc !== null && (float) $pc > 0) {
            $lockedSizes[] = ['label' => (string) $sz['label'], 'pct' => (float) $pc];
        }
    }
    ?>
    <?php if ($lockedSizes): ?>
      <p class="hint" style="margin-block-start:10px;color:var(--success)">
        درصد سود قفل‌شده:
        <?php foreach ($lockedSizes as $ls): ?>
          <br>
          «<?= e($ls['label']) ?>» —
          <b class="num"><?= e(number_format($ls['pct'], 1)) ?>٪</b> روی قیمت خرید،
          یعنی حاشیه
          <b class="num"><?= e(number_format($ls['pct'] / (1 + $ls['pct'] / 100), 1)) ?>٪</b>
          از قیمت فروش
        <?php endforeach; ?>
        <br>
        وقتی نرخ درهم بالا برود همین درصدها حفظ می‌شوند و مبلغ تومانی سود
        خودش بالا می‌رود. اگر سود یک اندازه را عوض کنید، درصدش از روی نرخ
        امروز دوباره حساب می‌شود.
      </p>
    <?php elseif ($sizes && ($product['price_mode'] ?? '') === 'aed'): ?>
      <p class="hint" style="margin-block-start:10px">
        درصد سود هیچ اندازه‌ای هنوز ثبت نشده — با اولین اعمال نرخ در صفحه
        قیمت‌گذاری قفل می‌شود.
      </p>
    <?php endif; ?>

    <p class="hint" style="margin-block-start:10px">
      اگر محصول فقط یک اندازه دارد، این بخش را خالی بگذارید — قیمت اصلی محصول استفاده می‌شود.
      <br>
      برای هر اندازه فقط یکی از دو ستون خرید را پر کنید: درهم برای جنس
      وارداتی، تومان برای جنسی که داخل ایران خریده می‌شود.
      <br>
      در حالت درهمی، قیمت فروش هر اندازه خودکار از قیمت خرید و سود همان
      اندازه ساخته می‌شود؛ خالی گذاشتن خرید یا سود یعنی از مقدار خود محصول
      استفاده شود.
    </p>

    <?php
    /* Anything beyond size and flavour. Those two keep their own editors
       above because they carry pricing the generic form has no place for -
       servings, two purchase currencies, a locked margin. A custom attribute
       is a list of choices with a picture, which is all a colour or a
       capsule count needs. */
    $customAttrs = array_values(array_filter(
        $isNew ? [] : variants_attributes($id),
        static fn($a) => !in_array($a['ext_id'], [VARIANT_SIZE_ATTR, VARIANT_FLAVOR_ATTR], true)
    ));
    ?>

    <h3 class="attr__head">ویژگی‌های دلخواه</h3>

    <?php if ($isNew): ?>
      <p class="hint">اول محصول را ذخیره کنید، بعد ویژگی تازه اضافه کنید.</p>
    <?php else: ?>
      <div class="attrs" data-attrs>
        <?php foreach ($customAttrs as $ai => $attr): ?>
          <div class="attr" data-attr>
            <div class="attr__row">
              <input type="hidden" name="attr_key[]" value="<?= e($attr['ext_id']) ?>">
              <input type="text" name="attr_name[]" value="<?= e($attr['name']) ?>"
                     placeholder="رنگ" aria-label="نام ویژگی">
              <label class="attr__del">
                <input type="checkbox" name="attr_drop[<?= e($attr['ext_id']) ?>]" value="1">
                حذف این ویژگی
              </label>
            </div>
            <?php /* One value per line: label, and a picture if this choice
                     looks different from the others. The id is generated from
                     the label, because nobody wants to invent two names for
                     one colour. */ ?>
            <div class="rows" data-repeat="attrval-<?= (int) $ai ?>">
              <div class="rows__row rows__row--attrval rows__head">
                <span>عنوان گزینه</span><span>تصویر</span><span></span>
              </div>
              <?php $vRows = $attr['values'] ?: [['ext_id' => '', 'label' => '', 'image' => '']]; ?>
              <?php foreach ($vRows as $vi => $val): ?>
                <div class="rows__row rows__row--attrval" data-row>
                  <input type="text" name="attr_val_label[<?= e($attr['ext_id']) ?>][]"
                         value="<?= e($val['label']) ?>" placeholder="قرمز">
                  <div class="flavor-img">
                    <?php if (($val['image'] ?? '') !== ''): ?>
                      <img class="flavor-img__thumb" src="<?= e(admin_img($val['image'])) ?>"
                           alt="" width="38" height="38">
                    <?php endif; ?>
                    <input type="file" name="attr_val_img_<?= e($attr['ext_id']) ?>[]" accept="image/*">
                  </div>
                  <input type="hidden" name="attr_val_id[<?= e($attr['ext_id']) ?>][]"
                         value="<?= e($val['ext_id']) ?>">
                  <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
                </div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn--ghost btn--sm" type="button"
                    data-add="attrval-<?= (int) $ai ?>" style="margin-block-start:8px">
              افزودن گزینه
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="attr__new">
        <input type="text" name="attr_new_name" placeholder="نام ویژگی تازه — مثلاً رنگ"
               aria-label="نام ویژگی تازه">
        <span class="hint">بعد از ذخیره، گزینه‌هایش را اینجا اضافه کنید.</span>
      </div>

      <?php
      /* Every combination the axes above imply. One row per real thing a
         shopper can buy, and once these exist they are what the shop sells
         from - lib/orders.php prices, counts and reserves against them
         instead of the size and the premium. */
      $gridRows = variants_list($id);

      /* Which combinations follow their size (or the product) for their
         purchase price. Those are drawn blank, with the figure they follow as
         the placeholder, so a purchase price typed on the size reaches them -
         the save reads a blank exactly that way (pricing_reprice_product()).
         A combination whose own figure differs keeps it, and shows it. */
      $gridFollow = [];
      if ($aedMode && $gridRows) {
          $gfProduct = (float) db_value('SELECT cost_aed FROM products WHERE id = ?', [$id]);
          $gfSize = [];
          foreach (db_all('SELECT ext_id, cost_aed FROM product_sizes WHERE product_id = ?', [$id]) as $gfr) {
              $gfSize[(string) $gfr['ext_id']] = (float) ($gfr['cost_aed'] ?? 0);
          }
          $gfFlavor = [];
          foreach (db_all('SELECT ext_id, cost_delta_aed FROM product_flavors WHERE product_id = ?', [$id]) as $gfr) {
              $gfFlavor[(string) $gfr['ext_id']] = (float) ($gfr['cost_delta_aed'] ?? 0);
          }
          foreach ($gridRows as $gv) {
              $gfS = null;
              $gfF = null;
              foreach ($gv['values'] as $gfv) {
                  if ($gfv['attr_ext'] === VARIANT_SIZE_ATTR)   { $gfS = (string) $gfv['ext_id']; }
                  if ($gfv['attr_ext'] === VARIANT_FLAVOR_ATTR) { $gfF = (string) $gfv['ext_id']; }
              }
              $gfBase = ($gfS !== null && ($gfSize[$gfS] ?? 0) > 0) ? $gfSize[$gfS] : $gfProduct;
              $gfInherit = round($gfBase + ($gfF !== null ? ($gfFlavor[$gfF] ?? 0.0) : 0.0), 2);
              $gfOwn = $gv['cost_aed'] === null ? 0.0 : (float) $gv['cost_aed'];
              $gridFollow[(int) $gv['id']] = [
                  'follows' => $gfOwn <= 0 || abs($gfOwn - $gfInherit) < 0.005,
                  'inherit' => $gfInherit,
              ];
          }
      }
      ?>
      <h3 class="attr__head">ترکیب‌ها</h3>

      <?php /* Always rendered, even with nothing in it: the browser fills this
               in live as sizes and flavours are typed, and it needs somewhere
               to put the rows. */ ?>
      <p class="hint" data-grid-empty<?= $gridRows ? ' hidden' : '' ?>>
        هنوز ترکیبی نیست. بالا یک اندازه یا طعم اضافه کنید — همین‌جا
        ساخته می‌شوند.
      </p>
        <?php /* The unit the purchase column is drawn in, for the same reason
                 as the flavours' flavor_unit. */ ?>
        <input type="hidden" name="grid_unit" value="<?= $aedMode ? 'aed' : 'toman' ?>">
        <p class="hint aed-out--big" data-grid-unit-warn hidden>
          ستون خرید این جدول به تومان است و در حالت درهمی خوانده نمی‌شود (پاک هم
          نمی‌شود): هر ترکیب خرید اندازه یا محصولش را می‌گیرد. بعد از ذخیره، اگر
          ترکیبی خرید دیگری دارد، به درهم واردش کنید.
        </p>
        <div class="rows" data-grid>
          <div class="rows__row rows__row--variant rows__head"<?= $gridRows ? '' : ' hidden' ?>>
            <span>ترکیب</span>
            <span>قیمت فروش <span class="auto-tag" data-auto-only<?= $autoNow ? '' : ' hidden' ?>>خودکار</span></span>
            <span><?= $aedMode ? 'خرید (درهم)' : 'خرید (تومان)' ?></span>
            <span>موجودی</span><span>تصویر</span><span>فعال</span>
          </div>
          <?php foreach ($gridRows as $gv): ?>
            <?php $gk = (string) $gv['ext_id']; ?>
            <div class="rows__row rows__row--variant">
              <input type="hidden" name="variant_ext[]" value="<?= e($gk) ?>">
              <span class="variant__name">
                <?= e(implode(' · ', array_column($gv['values'], 'label'))) ?>
              </span>
              <input type="text" name="variant_price[]" inputmode="numeric"<?= $autoNow ? ' readonly' : '' ?>
                     value="<?= e((string) $gv['price']) ?>"
                     aria-label="قیمت">
              <?php if ($aedMode): ?>
                <?php $gF = $gridFollow[(int) $gv['id']] ?? null; ?>
                <div class="aed-cell">
                  <input type="text" name="variant_cost[]" dir="ltr" inputmode="decimal"
                         data-aed value="<?= e(!empty($gF['follows']) ? '' : (string) ($gv['cost_aed'] ?? '')) ?>"
                         placeholder="<?= ($gF['inherit'] ?? 0) > 0 ? e((string) $gF['inherit']) : '' ?>"
                         aria-label="خرید درهمی">
                  <span class="hint" data-aed-out></span>
                </div>
              <?php else: ?>
                <input type="text" name="variant_cost[]" inputmode="numeric"
                       value="<?= e((string) ($gv['cost_toman'] ?? '')) ?>"
                       aria-label="خرید تومانی">
              <?php endif; ?>
              <?php /* Empty means «not counted», which is not the same as none
                       left - the whole schema draws that distinction. */ ?>
              <input type="text" name="variant_stock[]" inputmode="numeric"
                     placeholder="شمارش نمی‌شود"
                     value="<?= e($gv['stock_qty'] === null ? '' : (string) $gv['stock_qty']) ?>"
                     aria-label="موجودی">
              <div class="flavor-img">
                <?php if ((string) $gv['image'] !== ''): ?>
                  <img class="flavor-img__thumb" src="<?= e(admin_img($gv['image'])) ?>"
                       alt="" width="38" height="38">
                <?php endif; ?>
                <input type="file" name="variant_image_<?= (int) $gv['id'] ?>" accept="image/*">
              </div>
              <label class="variant__on">
                <input type="checkbox" name="variant_active[<?= e($gk) ?>]" value="1"
                       <?= (int) $gv['is_active'] === 1 ? 'checked' : '' ?>>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <p class="hint" style="margin-block-start:12px"<?= $gridRows ? '' : ' hidden' ?> data-grid-note>
          هر ردیف یک چیز واقعی است که مشتری می‌خرد. تا وقتی این جدول پر است،
          قیمت و موجودی همین‌جا ملاک است، نه اندازه و اضافه‌بهای طعم.
          <br>
          موجودی خالی یعنی «شمارش نمی‌شود» — با صفر فرق دارد.
          <br>
          تیک «فعال» را بردارید تا یک ترکیب فروخته نشود؛ سفارشی که آن را
          بخواهد رد می‌شود.
          <?php if ($aedMode && $aedRateNow !== null): ?>
            <br>
            در حالت درهمی قیمت هر ترکیب خودکار حساب می‌شود: خرید همان ترکیب
            × نرخ + سود اندازه‌اش (یا محصول). خریدِ خالی یعنی همان خرید اندازه
            (یا محصول) به‌اضافهٔ اضافه‌بهای طعم — همان عدد کم‌رنگ؛ عددی که
            بنویسید فقط مال همان ترکیب است. صفحهٔ قیمت‌گذاری هم با هر تغییر نرخ
            همین‌ها را به‌روز می‌کند.
          <?php elseif ($aedMode): ?>
            <br>
            تا نرخ درهم ثبت نشده، قیمت ترکیب‌ها را دستی وارد کنید.
          <?php endif; ?>
        </p>
    <?php endif; ?>
  </div>

  <div class="card" id="sec-nutrition" data-formnav-target>
    <h2 class="card__title">جدول ارزش غذایی</h2>
    <div class="field" style="max-width:340px;margin-block-end:14px">
      <label for="nutrition_serving">اندازه هر سروینگ</label>
      <input type="text" id="nutrition_serving" name="nutrition_serving"
             placeholder="هر پیمانه 30 گرم" value="<?= e(v($product, 'nutrition_serving')) ?>">
    </div>

    <div class="rows" data-repeat="nutrition">
      <div class="rows__row rows__row--3 rows__head">
        <span>عنوان</span><span>مقدار</span><span></span>
      </div>
      <?php
      $nutritionRows = $nutrition ?: [['label' => '', 'value' => '']];
      foreach ($nutritionRows as $n): ?>
        <div class="rows__row rows__row--3" data-row>
          <input type="text" name="nutrition_label[]" placeholder="پروتئین" value="<?= e($n['label'] ?? '') ?>">
          <input type="text" name="nutrition_value[]" dir="ltr" placeholder="24 g" value="<?= e($n['value'] ?? '') ?>">
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn--ghost btn--sm" type="button" data-add="nutrition" style="margin-block-start:10px">
      افزودن ردیف
    </button>
  </div>

  <?php /* Stays on screen while any of the form is: the page is long, and
           «ذخیره» used to be one screen below the last field typed in. */ ?>
  <div class="form-foot savebar">
    <button class="btn btn--primary" type="submit">
      <?= $isNew ? 'ساخت محصول' : 'ذخیره تغییرات' ?>
    </button>
    <a class="btn btn--ghost" href="products.php">انصراف</a>
    <span class="spacer"></span>
    <?php if (!$isNew): ?>
      <?php /* What is saved, not what is typed: a change not yet saved is not
               in the file. The same workbook as «ورود از اکسل», so the file
               goes back in there. */ ?>
      <a class="btn btn--ghost" href="import.php?export=xlsx&amp;product=<?= $id ?>"
         title="مشخصات و قیمت‌های ذخیره‌شدهٔ همین محصول در یک فایل اکسل">
        <?= admin_icon('download') ?> خروجی اکسل
      </a>
      <a class="btn btn--ghost" target="_blank" rel="noopener"
         href="../product.html?p=<?= e(rawurlencode(v($product, 'slug'))) ?>">
        مشاهده در سایت
      </a>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew && $images): ?>
  <?php /* Each picture's delete, out here because forms do not nest; the ×
           on its tile in the «عکس‌ها» box names it with form=. */ ?>
  <?php foreach ($images as $img): ?>
    <form id="del-img-<?= (int) $img['id'] ?>" method="post" action="product-edit.php?id=<?= $id ?>" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="del_image">
      <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
    </form>
  <?php endforeach; ?>

  <script>
  /* Arranging the images: drag and drop, and the two arrows for a touch
     screen or a keyboard, where dragging does not work. Nothing is saved
     here - the tiles and their order[] inputs move in the page, and the
     product form saves them. */
  (function () {
    var grid = document.querySelector('[data-img-sort]');
    if (!grid) { return; }
    var save  = document.querySelector('[data-img-save]');
    var dirty = document.querySelector('[data-img-dirty]');
    save.addEventListener('click', function () {
      var f = document.getElementById('product-form');
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
    var say   = document.querySelector('[data-img-say]');
    var rtl   = getComputedStyle(grid).direction === 'rtl';

    function items() { return Array.prototype.slice.call(grid.querySelectorAll('[data-img-item]')); }
    function order() {
      return items().map(function (it) { return it.querySelector('input[name="order[]"]').value; }).join(',');
    }
    function fa(n) {
      return String(n).replace(/[0-9]/g, function (d) { return String.fromCharCode(0x06F0 + (+d)); });
    }
    var initial = order();

    /* Numbers, the «اصلی» badge and the arrows at the ends follow the tiles. */
    function refresh() {
      var all = items();
      all.forEach(function (it, i) {
        it.querySelector('[data-img-pos]').textContent = fa(i + 1);
        it.querySelector('[data-img-main]').hidden = i !== 0;
        it.querySelector('[data-move="-1"]').disabled = i === 0;
        it.querySelector('[data-move="1"]').disabled = i === all.length - 1;
      });
      var changed = order() !== initial;
      save.disabled = !changed;
      dirty.hidden = !changed;
    }

    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-move]');
      if (!btn || btn.disabled) { return; }
      var it = btn.closest('[data-img-item]');
      if (btn.getAttribute('data-move') === '-1' && it.previousElementSibling) {
        grid.insertBefore(it, it.previousElementSibling);
      } else if (btn.getAttribute('data-move') === '1' && it.nextElementSibling) {
        grid.insertBefore(it.nextElementSibling, it);
      }
      refresh();
      /* The button travelled with its tile; keep the keyboard on it. At an end
         it is now disabled, so hand focus to the other arrow. */
      (btn.disabled ? it.querySelector('.gallery__arrow:not([disabled])') || btn : btn).focus();
      say.textContent = 'تصویر به جای ' + fa(items().indexOf(it) + 1) + ' رفت.';
    });

    var dragged = null;
    grid.addEventListener('dragstart', function (e) {
      dragged = e.target.closest('[data-img-item]');
      if (!dragged) { return; }
      dragged.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', ''); } catch (err) { /* Firefox needs data */ }
    });
    grid.addEventListener('dragover', function (e) {
      if (!dragged) { return; }
      e.preventDefault();
      var over = e.target.closest('[data-img-item]');
      if (!over || over === dragged) { return; }
      /* Which half of the tile the pointer is over decides before or after.
         In a right-to-left grid the earlier half is the right one. */
      var r = over.getBoundingClientRect();
      var rightHalf = e.clientX > r.left + r.width / 2;
      grid.insertBefore(dragged, (rightHalf === rtl) ? over : over.nextSibling);
      refresh();
    });
    grid.addEventListener('drop', function (e) { e.preventDefault(); });
    grid.addEventListener('dragend', function () {
      if (dragged) { dragged.classList.remove('is-dragging'); }
      dragged = null;
      refresh();
    });
  })();
  </script>
<?php endif; ?>

<style>
  /* The dirham cell holds its input and the converted figure, stacked. The row
     is a fixed grid, so without this the conversion would have to become a
     column of its own in every row including the header. */
  .aed-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .aed-cell .hint { font-size: .7rem; line-height: 1.3; }
  .aed-out--big { color: var(--amber); }

  /* Toggled by the price script as the mode is switched. The form's fields
     are flex and its rows grid, and either beats the browser's own rule for
     [hidden] - so without this nothing on the page could be hidden. */
  #product-form [hidden] { display: none !important; }

  /* A figure the formula owns: legible, and plainly not for typing. */
  #product-form input[readonly] {
    background: var(--surface);
    border-style: dashed;
    color: var(--text);
    cursor: default;
  }
  .auto-tag {
    display: inline-block;
    margin-inline-start: 6px;
    padding: 1px 8px;
    border-radius: var(--r-pill);
    background: rgba(242, 124, 61, .14);
    color: var(--accent);
    font-size: .6875rem;
    font-weight: 700;
    vertical-align: 1px;
  }

  /* The working, beside the figures it is worked from. */
  .auto-box {
    padding: 14px 16px;
    border: 1px solid rgba(242, 124, 61, .35);
    border-radius: var(--r-md);
    background: rgba(242, 124, 61, .06);
  }
  .auto-box__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; font-weight: 700; }
  .auto-box__head b { font-size: 1.375rem; color: var(--text); }
  .auto-box__head s { color: var(--text-dim); font-size: .875rem; font-weight: 400; }
  .auto-box__unit { color: var(--text-muted); font-size: .8125rem; font-weight: 400; }
  .auto-box__lines {
    display: grid;
    grid-template-columns: max-content max-content minmax(0, 1fr);
    gap: 4px 14px;
    align-items: baseline;
    font-size: .8125rem;
  }
  .auto-box__lines:empty { display: none; }
  .auto-box__lines > div { display: contents; }
  .auto-box__lines dt { color: var(--text-muted); }
  .auto-box__lines dd { margin: 0; }
  .auto-box__why { color: var(--text-dim); font-size: .75rem; }
  .auto-box__none { color: var(--text-muted); font-size: .8125rem; line-height: 1.9; }
  @media (max-width: 560px) {
    .auto-box__lines { grid-template-columns: max-content minmax(0, 1fr); }
    .auto-box__why { grid-column: 1 / -1; margin-block-end: 4px; }
  }
</style>

<script>
/* What a dirham figure is worth in toman, updated as it is typed.

   A dirham cost is not judgeable on its own: 29 or 290 both look like numbers,
   and only the toman figure makes a mistyped one obvious. Live rather than on
   save, because by the time it is saved the wrong number is already stored.

   The rate is whatever the pricing page last applied. When none has been set
   there is nothing to convert with, and the block stays silent rather than
   showing a zero. */
(function () {
  var RATE = <?= json_encode($aedRateNow) ?>;
  if (!RATE || RATE <= 0) { return; }

  /* An operator typing on a Persian keyboard produces ۰-۹, which parseFloat
     reads as NaN. The same reading pricing_parse_aed() gives the save: «٫»
     and «/» are a decimal point - «۴۲/۵» is 42.5 - where this used to drop
     «٫» and preview 42٫5 as 425 while the save stored 42. */
  function latin(s) {
    return String(s || '')
      .replace(/[\u06F0-\u06F9]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })
      .replace(/[\u0660-\u0669]/g, function (d) { return d.charCodeAt(0) - 0x0660; })
      .replace(/[\u066B\/]/g, '.')
      .replace(/[,\s\u066C\u200C\u200E\u200F]/g, '');
  }

  function render(input) {
    var out = input.parentNode.querySelector('[data-aed-out]');
    if (!out) { return; }
    var aed = parseFloat(latin(input.value));
    if (!isFinite(aed) || aed <= 0) { out.textContent = ''; return; }

    var toman = Math.round(aed * RATE);
    out.textContent = '≈ ' + toman.toLocaleString('en-US') + ' تومان';

    /* Warn on a large change from what this field already held, not on a large
       absolute figure. The mistake worth catching is a slipped digit — 290 for
       29 — and an absolute threshold cannot see it: 290 dirhams is a perfectly
       ordinary cost for a ten-pound gainer and a disaster for a bottle of
       softgels. The ratio against the stored value knows the difference, and
       says nothing at all on a new product, where there is no baseline and any
       threshold would be a guess. */
    var was = parseFloat(input.getAttribute('data-aed-was') || '');
    var slipped = isFinite(was) && was > 0 && (aed / was >= 5 || was / aed >= 5);
    out.classList.toggle('aed-out--big', slipped);
    if (slipped) {
      out.textContent += ' — ' + (aed > was ? 'خیلی بیشتر' : 'خیلی کمتر')
        + ' از ' + was.toLocaleString('en-US') + ' درهم قبلی';
    }
  }

  function renderAll() {
    document.querySelectorAll('[data-aed]').forEach(function (i) {
      /* Captured once, from the value the server rendered — not from the live
         value, or every keystroke would become the new baseline and the
         comparison would never fire. */
      if (!i.hasAttribute('data-aed-was')) {
        i.setAttribute('data-aed-was', latin(i.defaultValue));
      }
      render(i);
    });
  }

  document.addEventListener('input', function (ev) {
    if (ev.target.matches('[data-aed]')) { render(ev.target); }
  });

  renderAll();
  /* A cloned row carries a stale conversion until its input is touched, so the
     add button re-runs the whole set. */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-add]') || ev.target.closest('[data-del]')) {
      setTimeout(renderAll, 0);
    }
  });
})();
</script>

<script>
/* The combinations grid, rebuilt as the axes are typed.

   Everything here mirrors what the save will do; nothing here writes. The
   worst a mistake can cost is a row that the save then ignores, which is why
   the id rule below has to be exactly PHP's. */
(function () {
  var grid = document.querySelector('[data-grid]');
  if (!grid) { return; }

  var head  = grid.querySelector('.rows__head');
  var empty = document.querySelector('[data-grid-empty]');
  var note  = document.querySelector('[data-grid-note]');

  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var AR = '\u0660\u0661\u0662\u0663\u0664\u0665\u0666\u0667\u0668\u0669';

  /* lib/util.php slugify(), character for character: Persian and Arabic
     digits folded to Latin, every run that is neither letter nor number
     becomes one dash, dashes trimmed off the ends, lowercased, 110 max.

     If this drifts from the PHP the save will look up an id that does not
     exist and quietly drop what was typed into that row. */
  function slugify(s) {
    s = String(s == null ? '' : s).trim();
    s = s.replace(/[\u06F0-\u06F9]/g, function (d) { return String(FA.indexOf(d)); })
         .replace(/[\u0660-\u0669]/g, function (d) { return String(AR.indexOf(d)); });
    try {
      s = s.replace(/[^\p{L}\p{N}]+/gu, '-');
    } catch (e) {
      /* A browser without Unicode property escapes. Latin-only is wrong for
         Persian, so rather than build wrong ids the live grid stands down and
         the operator gets the old save-then-see behaviour. */
      return null;
    }
    s = s.replace(/^-+|-+$/g, '').toLowerCase();
    return s.slice(0, 110);
  }

  function val(el) { return el ? String(el.value || '').trim() : ''; }

  /* One axis per group of inputs, in the order the server sorts them:
     size, then flavour, then anything the owner added. */
  function readAxes() {
    var axes = [];

    function collect(rowsSel, idName, labelName) {
      var out = [];
      var group = document.querySelector(rowsSel);
      if (!group) { return out; }
      var rows = group.querySelectorAll('[data-row]');
      for (var i = 0; i < rows.length; i++) {
        var label = val(rows[i].querySelector('[name="' + labelName + '"]'));
        if (!label) { continue; }
        var ext = val(rows[i].querySelector('[name="' + idName + '"]')) || slugify(label);
        if (ext === null) { return null; }
        if (!ext) { continue; }
        out.push({ ext: ext, label: label });
      }
      return out;
    }

    var sizes = collect('[data-repeat="size"]', 'size_id[]', 'size_label[]');
    if (sizes === null) { return null; }
    if (sizes.length) { axes.push(sizes); }

    var flavors = collect('[data-repeat="flavor"]', 'flavor_id[]', 'flavor_name[]');
    if (flavors === null) { return null; }
    if (flavors.length) { axes.push(flavors); }

    /* Custom axes keep their own two fields, named per attribute. */
    var blocks = document.querySelectorAll('[data-attr]');
    for (var b = 0; b < blocks.length; b++) {
      var key = val(blocks[b].querySelector('[name="attr_key[]"]'));
      if (!key) { continue; }
      var dropped = blocks[b].querySelector('[name^="attr_drop"]');
      if (dropped && dropped.checked) { continue; }
      var vals = [];
      var vRows = blocks[b].querySelectorAll('[data-row]');
      for (var v = 0; v < vRows.length; v++) {
        var lab = val(vRows[v].querySelector('[name^="attr_val_label"]'));
        if (!lab) { continue; }
        var ex = val(vRows[v].querySelector('[name^="attr_val_id"]')) || slugify(lab);
        if (ex === null) { return null; }
        if (!ex) { continue; }
        vals.push({ ext: ex, label: lab });
      }
      if (vals.length) { axes.push(vals); }
    }

    return axes;
  }

  function combos(axes) {
    var out = [[]];
    for (var i = 0; i < axes.length; i++) {
      var next = [];
      for (var j = 0; j < out.length; j++) {
        for (var k = 0; k < axes[i].length; k++) {
          next.push(out[j].concat([axes[i][k]]));
        }
      }
      out = next;
    }
    return axes.length ? out : [];
  }

  /* A row for a combination that has no database row yet. Everything the
     save reads is here - the key, the price, the cost, the count, the tick.
     Only the picture is missing, because a file has nothing to be uploaded
     against until the row exists. */
  function newRow(ext, label, aed) {
    var el = document.createElement('div');
    el.className = 'rows__row rows__row--variant';
    el.setAttribute('data-grid-row', ext);
    el.setAttribute('data-fresh', '');
    el.innerHTML =
      '<input type="hidden" name="variant_ext[]">' +
      '<span class="variant__name"></span>' +
      '<input type="text" name="variant_price[]" inputmode="numeric" aria-label="قیمت">' +
      (aed
        ? '<div class="aed-cell"><input type="text" name="variant_cost[]" dir="ltr" ' +
          'inputmode="decimal" data-aed aria-label="خرید درهمی">' +
          '<span class="hint" data-aed-out></span></div>'
        : '<input type="text" name="variant_cost[]" inputmode="numeric" aria-label="خرید تومانی">') +
      '<input type="text" name="variant_stock[]" inputmode="numeric" ' +
        'placeholder="شمارش نمی‌شود" aria-label="موجودی">' +
      '<span class="hint">تصویر پس از ذخیره</span>' +
      '<label class="variant__on"><input type="checkbox" value="1" checked></label>';
    el.querySelector('[name="variant_ext[]"]').value = ext;
    el.querySelector('.variant__name').textContent = label;
    el.querySelector('.variant__on input').name = 'variant_active[' + ext + ']';
    return el;
  }

  var aedMode = !!document.querySelector('[name="price_mode"][value="aed"]:checked')
    || !!grid.querySelector('[name="variant_cost[]"][data-aed]');

  function rebuild() {
    var axes = readAxes();
    if (axes === null) { return; }          // no Unicode support; stand down

    var wanted = combos(axes);

    /* What is on screen now, by key. Existing rows are MOVED rather than
       rebuilt, so a price half-typed into one survives adding a flavour. */
    var have = {};
    var rows = grid.querySelectorAll('[data-grid-row]');
    for (var i = 0; i < rows.length; i++) {
      have[rows[i].getAttribute('data-grid-row')] = rows[i];
    }

    var order = [];
    for (var c = 0; c < wanted.length; c++) {
      var parts = [], labels = [];
      for (var p = 0; p < wanted[c].length; p++) {
        parts.push(wanted[c][p].ext);
        labels.push(wanted[c][p].label);
      }
      var key = parts.join('|');
      var row = have[key];
      if (row) {
        /* The labels can have been retyped since the page loaded. */
        var nameEl = row.querySelector('.variant__name');
        if (nameEl) { nameEl.textContent = labels.join(' · '); }
        delete have[key];
      } else {
        row = newRow(key, labels.join(' · '), aedMode);
      }
      order.push(row);
    }

    /* Anything left over is a combination that no longer exists. Removed from
       the screen only - the save decides what to delete, and it is the one
       that knows whether a row was ever written. */
    Object.keys(have).forEach(function (k) { have[k].remove(); });

    order.forEach(function (r) { grid.appendChild(r); });

    var any = order.length > 0;
    if (head)  { head.hidden  = !any; }
    if (note)  { note.hidden  = !any; }
    if (empty) { empty.hidden = any; }
  }

  /* Debounced: this runs on every keystroke in the labels above. */
  var timer = null;
  function schedule() {
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(rebuild, 250);
  }

  var card = grid.closest('.card');
  if (card) {
    card.addEventListener('input', function (e) {
      /* Only the axes. Typing a price into the grid must not rebuild the
         grid under the cursor. */
      if (e.target.closest('[data-grid]')) { return; }
      schedule();
    });
    card.addEventListener('change', function (e) {
      if (e.target.closest('[data-grid]')) { return; }
      schedule();
    });
    card.addEventListener('click', function (e) {
      /* Adding or deleting a row happens on click, and the row is gone by the
         time this bubbles. */
      if (e.target.closest('[data-add], [data-del]')) { schedule(); }
    });
  }

  /* Mark the rows PHP rendered, so the rebuild can find them. */
  var initial = grid.querySelectorAll('.rows__row--variant:not(.rows__head)');
  for (var n = 0; n < initial.length; n++) {
    var extEl = initial[n].querySelector('[name="variant_ext[]"]');
    if (extEl) { initial[n].setAttribute('data-grid-row', extEl.value); }
  }
})();
</script>

<script>
/* Add / remove repeating rows. A new row is cloned from the last one and
   cleared, so its field names and layout classes can never drift out of step
   with the markup PHP rendered. */
(function () {
  document.querySelectorAll('[data-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = document.querySelector('[data-repeat="' + btn.dataset.add + '"]');
      var rows  = group.querySelectorAll('[data-row]');
      var last  = rows[rows.length - 1];
      var clone = last.cloneNode(true);
      clone.querySelectorAll('input').forEach(function (i) {
        i.value = '';
        if (i.type === 'checkbox' || i.type === 'radio') { i.checked = false; }
        /* Nor anything else of the copied row's own: the price script's stash
           of a typed price, and the dirham check's baseline - which compared
           the new row's figure against the old row's and called it a slip. */
        i.removeAttribute('data-typed');
        i.removeAttribute('data-aed-was');
        if (i.type !== 'file' && i.type !== 'checkbox' && i.type !== 'radio') {
          i.defaultValue = '';
        }
      });
      /* Clearing the inputs is not enough once a row can hold a picture: the
         thumbnail is an <img> the server rendered, so a new row came up wearing
         the previous one's photograph while its own file box sat empty. */
      clone.querySelectorAll('.flavor-img__thumb, .rows__thumb')
           .forEach(function (t) { t.remove(); });
      group.appendChild(clone);
      var first = clone.querySelector('input');
      if (first) { first.focus(); }
    });
  });

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-del]');
    if (!btn) { return; }
    var group = btn.closest('[data-repeat]');
    var row   = btn.closest('[data-row]');
    /* Always leave one row standing — an empty group with no visible row and
       no way to get one back is a dead end. Clear it instead. */
    if (group.querySelectorAll('[data-row]').length > 1) {
      row.remove();
    } else {
      row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    }
  });
})();
</script>

<?php
/* What the price script needs to reach the save's answer on its own: the rate
   and the rounding step, and the few stored figures the save compares the
   typed ones against - the profit and ratio last saved on the product and on
   each size - plus the discounts the import set on sizes and combinations,
   which this page has no field for. From the database rather than $product,
   because after a refused save $product holds what was typed. */
$autoCfg = null;
if ($aedRateNow !== null) {
    $cfgProduct = $isNew ? null
        : db_one('SELECT profit_toman, profit_percent FROM products WHERE id = ?', [$id]);
    $cfgSizes = [];
    foreach ($isNew ? [] : db_all('SELECT ext_id, profit_toman, profit_percent, promo_toman
                                     FROM product_sizes WHERE product_id = ?', [$id]) as $r) {
        $cfgSizes[(string) $r['ext_id']] = [
            'profit' => $r['profit_toman'] === null ? null : (int) $r['profit_toman'],
            'pct'    => $r['profit_percent'] === null ? null : (float) $r['profit_percent'],
            'promo'  => (int) ($r['promo_toman'] ?? 0),
        ];
    }
    $cfgVariantPromo = [];
    foreach ($isNew ? [] : db_all('SELECT ext_id, promo_toman FROM product_variants
                                    WHERE product_id = ? AND promo_toman > 0', [$id]) as $r) {
        $cfgVariantPromo[(string) $r['ext_id']] = (int) $r['promo_toman'];
    }
    $autoCfg = [
        'rate'  => $aedRateNow,
        'step'  => pricing_step(),
        'share' => PRICING_MAX_PROMO_SHARE,
        'product' => [
            'profit' => ($cfgProduct['profit_toman'] ?? null) === null ? null : (int) $cfgProduct['profit_toman'],
            'pct'    => ($cfgProduct['profit_percent'] ?? null) === null ? null : (float) $cfgProduct['profit_percent'],
        ],
        'sizes'        => (object) $cfgSizes,
        'variantPromo' => (object) $cfgVariantPromo,
        /* The struck price is typed by hand, not the formula's. */
        'manualCompare' => pricing_manual_ready(),
    ];
}
?>
<script>
/* The price a dirham product will sell at, worked out while it is typed.

   The save prices the product itself - pricing_reprice_product() in
   lib/pricing.php - and trusts nothing posted from here. This is the same
   arithmetic done early, so the number is on screen before it is committed
   to, with the working: purchase × rate, plus profit, less the discount,
   rounded up. Every rule below is the PHP's, in the PHP's order, and
   tests/test-autoprice.mjs holds the two to each other; a figure that differs
   between this page and the saved product is a bug in one of them. */
(function () {
  var CFG  = <?= json_encode($autoCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var form = document.getElementById('product-form');
  if (!form) { return; }

  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var AR = '٠١٢٣٤٥٦٧٨٩';

  function latinDigits(s) {
    return String(s == null ? '' : s)
      .replace(/[\u06F0-\u06F9]/g, function (d) { return String(FA.indexOf(d)); })
      .replace(/[\u0660-\u0669]/g, function (d) { return String(AR.indexOf(d)); });
  }

  /* PHP's round(): half away from zero, once the binary noise that makes
     42.555 * 100 come out as 4255.4999... has been trimmed off. */
  function phpRound(x, places) {
    var f = Math.pow(10, places || 0);
    var y = Math.floor(Number((Math.abs(x) * f).toPrecision(15)) + 0.5) / f;
    return x < 0 ? -y : y;
  }

  /* pricing_parse_aed(). */
  function aedNum(v) {
    var s = latinDigits(v).replace(/[\u066B\/]/g, '.')
                          .replace(/[\s,\u066C\u200C\u200E\u200F]+/g, '');
    var m = /^(\d+(?:\.\d*)?|\.\d+)/.exec(s);
    return m ? phpRound(parseFloat(m[1]), 2) : 0;
  }

  /* parse_money(). */
  function toman(v) {
    var d = latinDigits(v).replace(/\D+/g, '');
    return d === '' ? 0 : parseInt(d, 10);
  }

  /* slugify() in lib/util.php, the way the grid script has it. Built at run
     time so a browser without \p{} loses only this, not the whole script. */
  var NOT_WORD = null;
  try { NOT_WORD = new RegExp('[^\\p{L}\\p{N}]+', 'gu'); } catch (e) { NOT_WORD = null; }
  function slugify(s) {
    if (!NOT_WORD) { return null; }
    s = latinDigits(String(s == null ? '' : s).trim()).replace(NOT_WORD, '-');
    return s.replace(/^-+|-+$/g, '').toLowerCase().slice(0, 110);
  }

  function q(sel, root) { return (root || form).querySelector(sel); }
  function qa(sel, root) { return Array.prototype.slice.call((root || form).querySelectorAll(sel)); }
  function val(el) { return el ? String(el.value || '').trim() : ''; }
  function fmt(n) { return Math.round(n).toLocaleString('en-US'); }

  /* pricing_round_up(), pricing_from_cost(), pricing_profit() with its
     pricing_ratio_slack(), and pricing_percent_from(). */
  function roundUp(n) {
    return CFG.step <= 1 ? n : Math.ceil(n / CFG.step) * CFG.step;
  }
  function fromCost(cost, profit, promo) {
    profit = Math.max(0, profit);
    var asked = Math.max(0, promo);
    promo = Math.min(asked, Math.floor(profit * CFG.share));
    var full = roundUp(cost + profit);
    var now  = promo > 0 ? roundUp(cost + profit - promo) : full;
    return { cost: cost, profit: profit, promo: promo, asked: asked,
             raw: cost + profit - promo, price: now, compare: now < full ? full : null };
  }
  function profitAt(typed, pct, cost) {
    typed = Math.max(0, typed);
    if (pct == null || pct <= 0) { return typed; }
    var derived = phpRound(cost * pct / 100);
    var slack = Math.ceil(Math.max(0, cost) * 0.00001) + 3;
    return (typed > 0 && Math.abs(derived - typed) <= slack) ? typed : derived;
  }
  function pctFrom(cost, profit) {
    return (cost > 0 && profit > 0) ? phpRound(profit / cost * 100, 3) : null;
  }

  function isAed() {
    var r = q('[name="price_mode"]:checked');
    return !!r && r.value === 'aed';
  }
  function isAuto() { return isAed() && !!CFG && CFG.rate > 0; }

  var MANUAL_COMPARE = !!(CFG && CFG.manualCompare);
  var AUTO_FIELDS = '[data-auto-price], [data-auto-cost], '
                  + '[name="size_price[]"], [name="variant_price[]"]'
                  + (MANUAL_COMPARE ? '' : ', [data-auto-compare]');

  /* What each mode shows, and the price fields locked while the formula owns
     them. A price typed in manual mode is put back if the mode is switched
     back before saving, rather than lost to the formula's. */
  /* The premium column follows the mode while it is empty: nothing typed in
     the old unit can be misread, so it simply changes its label, and the unit
     it posts (flavor_unit) changes with it. Holding figures, it keeps its
     unit - the save will not read them as the other currency - and says so. */
  function followUnit(aed) {
    var unitIn = q('[name="flavor_unit"]');
    if (!unitIn) { return; }
    var want = aed ? 'aed' : 'toman';
    var extras = qa('[name="flavor_extra[]"]');
    var typed = extras.some(function (el) { return val(el) !== ''; });
    if (unitIn.value !== want && !typed) {
      unitIn.value = want;
      var label = q('[data-flavor-unit-label]');
      if (label) { label.textContent = aed ? 'درهم' : 'تومان'; }
      extras.forEach(function (el) {
        el.inputMode = aed ? 'decimal' : 'numeric';
        el.placeholder = aed ? '2.50' : '150000';
        if (aed) {
          el.setAttribute('data-aed', '');
          if (el.parentNode && !el.parentNode.querySelector('[data-aed-out]')) {
            var out = document.createElement('span');
            out.className = 'hint';
            out.setAttribute('data-aed-out', '');
            el.parentNode.appendChild(out);
          }
        } else {
          el.removeAttribute('data-aed');
          var o = el.parentNode && el.parentNode.querySelector('[data-aed-out]');
          if (o) { o.remove(); }
        }
      });
    }
    var warn = q('[data-flavor-unit-warn]');
    if (warn) { warn.hidden = unitIn.value === want; }

    /* The grid never changes its unit on the page - its purchase column holds
       figures the save must not erase - so it only says what will happen. */
    var gridUnit = q('[name="grid_unit"]');
    var gridWarn = q('[data-grid-unit-warn]');
    if (gridUnit && gridWarn) {
      gridWarn.hidden = !(aed && gridUnit.value !== 'aed'
        && qa('[name="variant_cost[]"]').some(function (el) { return val(el) !== ''; }));
    }
  }

  function applyMode() {
    var aed = isAed(), auto = isAuto();
    followUnit(aed);
    qa('[data-aed-only]').forEach(function (el) { el.hidden = !aed; });
    qa('[data-auto-only]').forEach(function (el) { el.hidden = !auto; });
    qa('[data-manual-only]').forEach(function (el) { el.hidden = auto; });
    qa(AUTO_FIELDS).forEach(function (el) {
      if (auto && !el.readOnly) {
        el.setAttribute('data-typed', el.value);
        el.readOnly = true;
      } else if (!auto && el.readOnly) {
        el.readOnly = false;
        if (el.hasAttribute('data-typed')) {
          el.value = el.getAttribute('data-typed');
          el.removeAttribute('data-typed');
        }
      }
    });
  }

  function cheapest(list) {
    var best = null;
    list.forEach(function (u) { if (!best || u.res.price < best.res.price) { best = u; } });
    return best;
  }

  function run() {
    applyMode();
    if (!isAuto()) { return; }
    var rate = CFG.rate;

    /* The product: its figures, and the ratio the save keeps or sets - kept
       only while the profit is the one last saved. */
    var pAed   = aedNum(val(q('#cost_aed')));
    var pCost  = phpRound(pAed * rate);
    var pTyped = toman(val(q('#profit_toman')));
    var pPromo = toman(val(q('#promo_toman')));
    var saved  = CFG.product || {};
    var pKept  = saved.pct != null && saved.profit != null && pTyped === saved.profit;
    var pPct   = pKept ? saved.pct : pctFrom(pCost, pTyped);
    var pProf  = profitAt(pTyped, pPct, pCost);
    var product = pAed > 0 ? {
      label: '', aed: pAed, cost: pCost, profit: pProf, pct: pPct,
      ratioMoved: pKept && pProf !== pTyped, res: fromCost(pCost, pProf, pPromo)
    } : null;

    /* Sizes, with pricing_units()' fallbacks: a blank is the product's. */
    var sizes = [], sizeBase = {}, sizeList = [];
    qa('[data-repeat="size"] [data-row]').forEach(function (row) {
      var label = val(q('[name="size_label[]"]', row));
      if (!label) { return; }
      var ext = val(q('[name="size_id[]"]', row)) || slugify(label);
      if (!ext) { return; }
      sizeList.push({ ext: ext, label: label });
      var prev = val(q('[name="size_prev_id[]"]', row));
      var st = (CFG.sizes && (CFG.sizes[prev] || CFG.sizes[ext])) || null;
      var sAed = aedNum(val(q('[name="size_cost_aed[]"]', row)));
      var sTyped = toman(val(q('[name="size_profit[]"]', row)));
      var sPct = null;
      if ((st ? (st.profit || 0) : 0) === sTyped && st && st.pct != null) {
        sPct = st.pct;
      } else {
        var basis = sAed > 0 ? sAed : pAed;
        sPct = (basis > 0 && sTyped > 0) ? pctFrom(phpRound(basis * rate), sTyped) : null;
      }
      var uAed = sAed > 0 ? sAed : pAed;
      if (uAed <= 0) { return; }
      var uCost = phpRound(uAed * rate);
      var uPct  = sPct != null ? sPct : pPct;
      var uProf = profitAt(sTyped > 0 ? sTyped : pTyped, uPct, uCost);
      var unit  = { label: label, aed: uAed, cost: uCost, profit: uProf, pct: uPct,
                    res: fromCost(uCost, uProf, (st && st.promo > 0) ? st.promo : pPromo) };
      var input = q('[name="size_price[]"]', row);
      if (input) { input.value = String(unit.res.price); }
      sizes.push(unit);
      sizeBase[ext] = unit;
    });

    /* Flavour premiums, read only when the column is drawn in dirhams: one
       drawn in toman is dropped by the save, so it is ignored here too. */
    var premium = {}, flavorList = [];
    var premiumOk = (q('[name="flavor_unit"]') || {}).value === 'aed';
    qa('[data-repeat="flavor"] [data-row]').forEach(function (row) {
      var name = val(q('[name="flavor_name[]"]', row));
      if (!name) { return; }
      var ext = val(q('[name="flavor_id[]"]', row)) || slugify(name);
      if (!ext) { return; }
      flavorList.push({ ext: ext, label: name });
      premium[ext] = premiumOk ? aedNum(val(q('[name="flavor_extra[]"]', row))) : 0;
    });

    /* A combination: its size (or the product), its own purchase price or
       the one it follows, and its size's profit scaled by purchase price. */
    function combo(sizeExt, flavorExt, ownAed, key, label, active) {
      var base = (sizeExt != null && sizeBase[sizeExt]) ? sizeBase[sizeExt] : product;
      if (!base) { return null; }
      var inherit = phpRound(base.aed + (flavorExt != null ? (premium[flavorExt] || 0) : 0), 2);
      var aed  = ownAed > 0 ? ownAed : inherit;
      var cost = phpRound(aed * rate);
      var scaled = base.cost > 0 ? phpRound(base.profit * cost / base.cost) : base.profit;
      var prof = profitAt(scaled, base.pct, cost);
      var promo = (key && CFG.variantPromo && CFG.variantPromo[key] > 0) ? CFG.variantPromo[key] : pPromo;
      return { label: label, inherit: inherit, aed: aed, cost: cost, profit: prof, pct: base.pct,
               active: active, res: fromCost(cost, prof, promo) };
    }

    var variants = [];
    var hasS = sizeList.length > 0, hasF = flavorList.length > 0;
    var grid = q('[data-grid]');
    var gRows = grid ? qa('.rows__row--variant:not(.rows__head)', grid) : [];
    var gridOk = (q('[name="grid_unit"]') || {}).value === 'aed';
    if (gRows.length) {
      gRows.forEach(function (row) {
        var key = val(q('[name="variant_ext[]"]', row));
        if (!key) { return; }
        var parts = key.split('|');
        var costIn = q('[name="variant_cost[]"]', row);
        var on = q('.variant__on input', row);
        var nameEl = q('.variant__name', row);
        var v = combo(hasS ? parts[0] : null, hasF ? parts[hasS ? 1 : 0] : null,
                      (gridOk && costIn) ? aedNum(val(costIn)) : 0, key,
                      nameEl ? nameEl.textContent.trim() : key, !on || on.checked);
        if (!v) { return; }
        var priceIn = q('[name="variant_price[]"]', row);
        if (priceIn) { priceIn.value = String(v.res.price); }
        if (costIn && gridOk) { costIn.placeholder = String(v.inherit); }
        variants.push(v);
      });
    } else if (hasS || hasF) {
      /* Not saved yet, so no grid: the save will make one combination per
         size and flavour, each costing what its size (or the product) does
         plus the flavour's premium - priced the same way here. */
      (hasS ? sizeList : [null]).forEach(function (s) {
        (hasF ? flavorList : [null]).forEach(function (f) {
          var v = combo(s ? s.ext : null, f ? f.ext : null, 0, '',
                        [s ? s.label : '', f ? f.label : ''].filter(Boolean).join(' · '), true);
          if (v) { variants.push(v); }
        });
      });
    }

    /* pricing_sync_parent(): the card shows the cheapest combination on sale,
       else the cheapest size, else the product itself. */
    var onSale = variants.filter(function (v) { return v.active && v.res.price > 0; });
    var pick = cheapest(onSale) || cheapest(sizes)
            || ((variants.length || sizes.length) ? null : product);
    var units = variants.length || sizes.length;
    var pool  = onSale.length ? onSale : sizes;
    var same  = !!pick && pool.length > 1
             && pool.every(function (u) { return u.res.price === pick.res.price; });
    var kind  = variants.length ? 'ترکیب' : 'اندازه';

    if (pick) {
      q('#price').value = String(pick.res.price);
      if (!MANUAL_COMPARE) {
        q('#compare_at').value = pick.res.compare ? String(pick.res.compare) : '';
      }
    }
    /* The toman cost the save stores beside that price: the product's own,
       or - for a product with sizes - the cost of the unit the card shows,
       which is what pricing_sync_parent() writes. */
    var costToman = q('[data-auto-cost]');
    if (costToman) { costToman.value = pick ? String(pick.res.cost) : (pAed > 0 ? String(pCost) : ''); }
    render(pick, product, pTyped, { units: units, same: same, count: pool.length, kind: kind });
  }

  function render(pick, product, pTyped, many) {
    var priceEl = q('[data-sum-price]');
    if (!priceEl) { return; }
    var cmpEl = q('[data-sum-compare]'), lines = q('[data-sum-lines]'), note = q('[data-sum-note]');
    var title = q('[data-sum-title]');
    lines.textContent = '';
    note.textContent = '';

    if (!product) {
      priceEl.textContent = '—';
      cmpEl.hidden = true;
      note.textContent = 'قیمت خرید به درهم را وارد کنید تا قیمت فروش حساب شود.';
      return;
    }
    if (!pick) {
      priceEl.textContent = '—';
      cmpEl.hidden = true;
      note.textContent = 'هیچ ترکیبی فعال نیست، پس قیمتی برای کارت محصول نمی‌ماند.';
      return;
    }

    var r = pick.res;
    /* pricing_place_manual_compare(): a typed struck price stands beside the
       card's price while that price is below it; otherwise the discount's. */
    var typedCmp = MANUAL_COMPARE ? toman(val(q('#compare_at'))) : 0;
    var shownCmp = typedCmp > r.price ? typedCmp : r.compare;
    title.textContent = (many.units > 1 && !many.same) ? 'کمترین قیمت فروش' : 'قیمت فروش';
    priceEl.textContent = fmt(r.price);
    cmpEl.hidden = !shownCmp;
    cmpEl.textContent = shownCmp ? fmt(shownCmp) : '';

    function line(term, value, why) {
      var wrap = document.createElement('div');
      var dt = document.createElement('dt');
      var dd = document.createElement('dd');
      var dw = document.createElement('dd');
      dt.textContent = term;
      dd.className = 'num';
      dd.textContent = value;
      dw.className = 'auto-box__why';
      dw.textContent = why || '';
      wrap.appendChild(dt); wrap.appendChild(dd); wrap.appendChild(dw);
      lines.appendChild(wrap);
    }
    line('قیمت خرید', fmt(r.cost), pick.aed + ' درهم × نرخ ' + fmt(CFG.rate));
    line('+ سود', fmt(r.profit),
         r.cost > 0 ? (Math.round(r.profit / r.cost * 1000) / 10) + '٪ روی قیمت خرید' : '');
    if (r.asked > 0) {
      line('− تخفیف', fmt(r.promo), r.promo < r.asked
        ? 'از ' + fmt(r.asked) + ' — بیشتر از نصف سود اعمال نمی‌شود'
        : (typedCmp > r.price ? 'از قیمت کم می‌شود' : 'قیمت کامل خط می‌خورد'));
    }
    if (r.price > r.raw) {
      line('+ گرد کردن', fmt(r.price - r.raw), 'به بالا، تا مضرب ' + fmt(CFG.step) + ' تومان');
    }

    var notes = [];
    if (product.ratioMoved && pick === product) {
      notes.push('سود با همان درصد ثبت‌شده (' + product.pct + '٪ روی قیمت خرید) از روی قیمت خرید '
        + 'تازه حساب شد، نه عدد ' + fmt(pTyped) + '. اگر عدد دیگری می‌خواهید، خود سود را عوض کنید.');
    }
    if (many.same) {
      notes.push('هر ' + many.count + ' ' + many.kind + ' همین قیمت را دارند.');
    } else if (many.units > 1) {
      notes.push('ارزان‌ترین' + (pick.label ? ' «' + pick.label + '»' : '') + ' است، که روی کارت '
        + 'محصول دیده می‌شود. قیمت هر اندازه و ترکیب جداگانه در بخش «ویژگی‌ها» حساب شده.');
    }
    if (typedCmp > r.price) {
      notes.push('قیمت خط‌خورده همان عددی است که در «قیمت قبل از تخفیف» نوشته‌اید؛ ثابت است و با نرخ '
        + 'حرکت نمی‌کند.');
    } else if (typedCmp > 0) {
      notes.push('قیمت قبل از تخفیف (' + fmt(typedCmp) + ') از قیمت فروش بیشتر نیست، پس نمایش داده نمی‌شود.');
    }
    if (pTyped <= 0) {
      notes.push('سود را هم وارد کنید؛ ذخیره بدون آن پذیرفته نمی‌شود.');
    }
    note.textContent = notes.join(' ');
  }

  var timer = null;
  function schedule() {
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(run, 80);
  }
  form.addEventListener('input', schedule);
  form.addEventListener('change', schedule);
  form.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-add], [data-del]')) { schedule(); }
  });
  /* The grid rebuilds itself a moment after an axis is typed; price the rows
     it lands with rather than the ones it replaced. */
  var gridEl = q('[data-grid]');
  if (gridEl && window.MutationObserver) {
    new MutationObserver(schedule).observe(gridEl, { childList: true });
  }
  run();
})();
</script>

<?php admin_foot(); ?>
