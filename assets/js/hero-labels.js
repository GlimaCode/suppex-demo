/* ===========================================================================
   SUPPEX — the labels on the 3D tub
   ---------------------------------------------------------------------------
   The tub on the homepage carried one baked artwork: the label that came
   inside the supplied .glb, for a product this shop does not sell. It said
   nothing, and it never changed.

   This shop resells whatever it stocks, so the useful thing to put there is
   what is actually discounted right now. The tub becomes a rotating shelf:
   each face is drawn from a real product row, and clicking it opens that
   product.

   Drawn on a canvas rather than composited from photographs for two reasons.
   A photograph of a tub wrapped onto a tub reads as a mistake — you see the
   seam and the second lid. And the shop has no photographs yet, while it does
   have names, brands, sizes and prices, which is what a label is mostly made
   of anyway.

   Canvas2d shapes Arabic correctly, unlike the server-side path in
   lib/arabic.php: the browser has a text engine and a bitmap does not.
   =========================================================================== */

(function () {
  'use strict';

  window.SUPPEX = window.SUPPEX || {};

  /* The label patch on the mesh measures 1.43 : 1 and its UVs span 0..1, so
     the canvas has to match that ratio or the artwork stretches. */
  var W = 1024;
  var H = 716;

  /* How many faces the tub cycles through. Past about five nobody waits to see
     the rest, and every one costs a texture upload. */
  var MAX = 5;

  var FA = '"Vazirmatn", system-ui, sans-serif';
  var LAT = '"Archivo", "Vazirmatn", system-ui, sans-serif';

  function money(n) {
    return (n || 0).toLocaleString('en-US');
  }

  /* Persian digits for the badge, which is the one place the site uses them —
     it reads as a sticker rather than as data. */
  function faDigits(s) {
    var d = '۰۱۲۳۴۵۶۷۸۹';
    return String(s).replace(/[0-9]/g, function (c) { return d[+c]; });
  }

  /** Shrink the type until the line fits, rather than letting it run off. */
  function fitText(g, text, weight, family, start, max) {
    var size = start;
    do {
      g.font = weight + ' ' + size + 'px ' + family;
      if (g.measureText(text).width <= max) { break; }
      size -= 2;
    } while (size > 14);
    return size;
  }

  /**
   * Draw one product as a tub label.
   *
   * @param {object} p  a product row from the repo
   * @returns {HTMLCanvasElement}
   */
  function drawLabel(p) {
    var c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    var g = c.getContext('2d');

    /* --- ground ---------------------------------------------------------- */
    var bg = g.createLinearGradient(0, 0, W, H);
    bg.addColorStop(0, '#1A1815');
    bg.addColorStop(0.55, '#221E19');
    bg.addColorStop(1, '#0E0D0C');
    g.fillStyle = bg;
    g.fillRect(0, 0, W, H);

    /* A copper wash from the top, so the printed area has some depth under it
       rather than sitting flat on black. */
    var glow = g.createRadialGradient(W / 2, -80, 40, W / 2, 260, 620);
    glow.addColorStop(0, 'rgba(242,89,20,0.42)');
    glow.addColorStop(1, 'rgba(242,89,20,0)');
    g.fillStyle = glow;
    g.fillRect(0, 0, W, H);

    /* Trim top and bottom: on a cylinder these read as the printed band's
       edges and stop the artwork floating. */
    g.fillStyle = 'rgba(242,237,227,0.20)';
    g.fillRect(0, 0, W, 8);
    g.fillStyle = 'rgba(0,0,0,0.42)';
    g.fillRect(0, H - 10, W, 10);

    g.textAlign = 'center';
    g.direction = 'rtl';

    /* --- brand ----------------------------------------------------------- */
    var brand = (p.brand || 'SUPPEX').toUpperCase();
    g.font = '700 34px ' + LAT;
    g.letterSpacing = '10px';
    g.fillStyle = '#F25914';
    g.fillText(brand, W / 2, 104);
    g.letterSpacing = '0px';

    g.strokeStyle = 'rgba(242,237,227,0.28)';
    g.lineWidth = 3;
    g.beginPath();
    g.moveTo(W / 2 - 130, 132);
    g.lineTo(W / 2 + 130, 132);
    g.stroke();

    /* --- the name, Persian first because that is what is recognised ------ */
    var nameFa = p.nameFa || p.name || '';
    var size = fitText(g, nameFa, '800', FA, 96, W - 130);
    g.fillStyle = '#F7F3EC';
    g.fillText(nameFa, W / 2, 268);

    var latin = (p.name || '').toUpperCase();
    if (latin && latin !== nameFa.toUpperCase()) {
      fitText(g, latin, '600', LAT, 34, W - 200);
      g.fillStyle = 'rgba(242,237,227,0.52)';
      g.letterSpacing = '4px';
      g.fillText(latin, W / 2, 322);
      g.letterSpacing = '0px';
    }

    /* --- size, when the product has one --------------------------------- */
    var variant = p.variantLabel || '';
    if (variant) {
      g.font = '600 30px ' + FA;
      g.fillStyle = 'rgba(242,237,227,0.62)';
      g.fillText(variant, W / 2, 390);
    }

    /* --- price ----------------------------------------------------------- */
    var price = p.price || 0;
    var was = p.onSale ? (p.compareAt || 0) : 0;

    if (was > price) {
      g.font = '500 34px ' + LAT;
      g.fillStyle = 'rgba(242,237,227,0.42)';
      var oldText = money(was);
      var ow = g.measureText(oldText).width;
      g.fillText(oldText, W / 2, 486);
      g.strokeStyle = 'rgba(242,237,227,0.42)';
      g.lineWidth = 3;
      g.beginPath();
      g.moveTo(W / 2 - ow / 2 - 6, 474);
      g.lineTo(W / 2 + ow / 2 + 6, 474);
      g.stroke();
    }

    g.font = '800 68px ' + LAT;
    g.fillStyle = '#F7F3EC';
    var priceText = money(price);
    var pw = g.measureText(priceText).width;
    g.fillText(priceText, W / 2, 570);

    g.font = '600 30px ' + FA;
    g.fillStyle = 'rgba(242,237,227,0.66)';
    g.fillText('تومان', W / 2 + pw / 2 + 54, 566);

    /* --- the saving, which is the reason this product is on the tub ------ */
    if (was > price) {
      var off = Math.round((1 - price / was) * 100);
      if (off > 0) {
        var badge = faDigits(off) + '٪';
        g.font = '800 40px ' + FA;
        var bw = g.measureText(badge).width + 52;
        var bx = W / 2 - bw / 2;
        var by = 620;

        g.fillStyle = '#F25914';
        if (g.roundRect) {
          g.beginPath();
          g.roundRect(bx, by, bw, 62, 31);
          g.fill();
        } else {
          g.fillRect(bx, by, bw, 62);
        }
        g.fillStyle = '#fff';
        g.fillText(badge, W / 2, by + 45);
      }
    }

    return c;
  }

  /**
   * Pick what belongs on the tub.
   *
   * Only real savings, biggest first. A product with no discount has no reason
   * to be here — the tub is the shop's discount shelf, and padding it with
   * full-price items would make the whole thing mean nothing.
   */
  function pickDeals(list) {
    return (list || [])
      .filter(function (p) {
        return p && p.inStock !== false && p.onSale && p.compareAt > p.price && p.price > 0;
      })
      .map(function (p) {
        p._off = 1 - p.price / p.compareAt;
        return p;
      })
      .sort(function (a, b) { return b._off - a._off; })
      .slice(0, MAX);
  }

  SUPPEX.heroLabels = {
    draw: drawLabel,
    pick: pickDeals,
    size: { w: W, h: H },
    max: MAX,
  };
}());
