/* ==========================================================================
   SUPPEX — Application configuration
   --------------------------------------------------------------------------
   Everything environment- or brand-specific lives here so that nothing else
   in the codebase hard-codes it. Renaming the brand, switching currency, or
   pointing the front-end at a real API is a change to this file only.

   Classic script (no ES modules) on purpose: the prototype must open by
   double-clicking index.html, and `import` is blocked by CORS on file://.
   ========================================================================== */
window.SUPPEX = window.SUPPEX || {};

SUPPEX.config = {
  /* --- Brand ---------------------------------------------------------- */
  brand: {
    name: 'SUPPEX',
    legalName: 'شرکت مکمل‌های ورزشی ساپکس',
    tagline: 'مکمل ورزشی، بدون حاشیه',
    established: 1396,
    phone: '021-88776655',
    email: 'info@suppex.ir',
    address: 'تهران، خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۲۴۱',
  },

  /* --- Commerce ------------------------------------------------------- */
  currency: {
    code: 'IRT',
    label: 'تومان',
    /* Prices are stored as plain integers in Toman. Formatting is centralised
       here so a future switch to Rial (or a server-driven currency) touches
       one function. Digits stay Latin per the brand decision. */
    format: function (value) {
      return new Intl.NumberFormat('en-US').format(Math.round(value));
    },
  },
  freeShippingThreshold: 1500000,
  shippingFlatRate: 65000,

  /* --- How an order actually gets placed -------------------------------
     Phase 1 deliberately has no payment gateway. The cart is composed into a
     ready-made message and handed to a messaging app, so the seller keeps
     receiving orders exactly where they already do — no gateway, no trust
     seal, no company registration needed to go live.
     Phase 2 replaces the checkout handler with a gateway redirect; nothing
     else in the cart or catalogue is touched.                              */
  ordering: {
    /* Prepended to the generated order message. */
    intro: 'سلام، می‌خواستم این سفارش را ثبت کنم:',

    /* Beyond this the order is handed over by clipboard instead of in the URL.

       Persian percent-encodes to ~4.6x its character count, so the URL grows
       fast: 1 item ~1,800 chars, 2 items ~2,100, 5 items ~2,900. The first
       value here was 2000, borrowed from the generic "safe URL length" advice
       that dates to old servers — it turned out to push every order past one
       item onto the clipboard, which defeated the point of prefill entirely.

       4000 covers a realistic cart (about six items with a full address) while
       still refusing the extreme case. It is a measured guess, not a published
       limit: no app documents its ceiling, and the failure mode is silent
       truncation, so verify with a real multi-item order before raising it. */
    maxPrefillUrlLength: 4000,

    /* Every way a shopper may send their order. The first enabled one is the
       primary button; the rest sit under it.

       prefillParam is the query parameter that carries the order text in the
       URL. Telegram, WhatsApp and Bale all accept `text` — Bale was checked on
       a real device. For anything still unverified, such as Eitaa, leave it
       null: the order is copied to the clipboard first and the shopper pastes
       it into the chat, which works on every app regardless of support. */
    channels: [
      {
        id: 'telegram',
        label: 'تلگرام',
        enabled: true,
        url: 'https://t.me/ARSENX2003',
        prefillParam: 'text',
      },
      {
        id: 'bale',
        label: 'بله',
        enabled: true,
        url: 'https://ble.ir/ALIARSENX',
        prefillParam: 'text',               // verified on a device: Bale prefills the composer
      },
      {
        id: 'whatsapp',
        label: 'واتس‌اپ',
        enabled: false,
        url: 'https://wa.me/989121234567',  // country code, digits only
        prefillParam: 'text',
      },
      {
        id: 'eitaa',
        label: 'ایتا',
        enabled: false,
        url: 'https://eitaa.com/ARSENX2003',
        prefillParam: null,
      },
    ],
  },

  /* --- Order notification (SMS, etc.) ----------------------------------
     DELIBERATELY OFF, and it cannot be switched on from here alone.

     Sending an SMS needs a provider API key. This file is downloaded by every
     visitor, so a key placed in it is public: anyone could read it and burn
     the shop's SMS credit. There is no client-side way around that.

     What this hook does is POST the order to an endpoint you control — a small
     serverless function that holds the key and calls the SMS provider. Set the
     URL once that exists. The endpoint must rate-limit and validate, because a
     public page can be made to call it by anyone. */
  notify: {
    webhookUrl: null,
  },

  /* --- Payment details shown after an order is placed -------------------
     Card-to-card, which is how this shop already gets paid. The details are
     revealed only once the shopper has actually placed an order, not printed
     on a public page for anyone to scrape and reuse in a scam.

     cardNumber is a deliberately INVALID placeholder: it fails the Luhn check
     every Iranian bank runs, so a transfer to it is rejected rather than
     landing in a stranger's account if this ships unedited. Replace it with
     the real one — assets/js/app.js warns in the console if the replacement
     is not a valid 16-digit card number. */
  payment: {
    method: 'card',                        // 'card' | 'gateway' | 'none'
    cardNumber: '6037 9911 0000 0000',     // ← شماره کارت واقعی را اینجا بگذارید
    cardHolder: 'نام صاحب حساب',           // ← نام دقیقاً همان‌طور که در بانک ثبت شده
    bankName: 'بانک ملی',
    /* {channel} is replaced with the app the shopper actually chose, so a
       Bale customer is never told to go and find Telegram. */
    note: 'پس از واریز، رسید را در همان گفتگوی {channel} بفرستید تا سفارش نهایی شود.',
  },

  /* --- Details collected before the order is sent -----------------------
     Without these the seller has to ask for the address and phone number by
     hand, one message at a time, which is most of the back-and-forth the site
     is meant to remove. They are appended to the order message.

     Everything stays in the browser (localStorage) so a returning customer
     does not retype it — there is no server to hold it. */
  customerFields: [
    { id: 'name',    label: 'نام و نام خانوادگی', type: 'text',  required: true,
      autocomplete: 'name' },
    { id: 'phone',   label: 'شماره تماس',        type: 'tel',   required: true,
      autocomplete: 'tel', placeholder: '09xxxxxxxxx', validate: 'mobile' },
    { id: 'address', label: 'آدرس دقیق',         type: 'textarea', required: true,
      autocomplete: 'street-address', rows: 3 },
    { id: 'postal',  label: 'کد پستی',           type: 'text',  required: true,
      autocomplete: 'postal-code', placeholder: '۱۰ رقم', validate: 'postal' },
    { id: 'note',    label: 'توضیحات (اختیاری)', type: 'textarea', required: false,
      rows: 2, placeholder: 'مثلاً ساعت مناسب تحویل' },
  ],

  /* --- Feature flags --------------------------------------------------
     The reference designs gated seasonal UI behind booleans; same idea here.
     Flip a flag to preview the campaign state during a client review.       */
  flags: {
    saleMode: true,       // show discount badges and struck-through prices
    showPromoBanner: true,
    show3DHero: true,     // false => static poster everywhere
    persistCart: true,    // localStorage; swap for a server cart later

    /* Social proof is OFF by default, on purpose.
       Star ratings, review counts and press mentions are the one part of a
       storefront that cannot be filled with placeholder content: invented
       numbers on a demo make the whole thing look dishonest, and a shop
       that has not launched yet has no reviews to show. Turn these on once
       there are real orders behind them. */
    showRatings: false,        // per-product stars and review counts
    showTestimonials: false,   // reviews section, press strip, headline rating
  },

  /* --- Data source ----------------------------------------------------
     baseUrl === null  →  read from the bundled catalogue (assets/js/catalog.js)
     baseUrl === '...' →  SUPPEX.repo issues fetch() calls instead.
     The repository layer already speaks Promises, so switching sources does
     not change a single caller.                                             */
  api: {
    baseUrl: null,
    endpoints: {
      products: '/api/products',
      product: '/api/products/:slug',
      categories: '/api/categories',
      search: '/api/search',
      cart: '/api/cart',
    },
  },

  /* --- 3D hero --------------------------------------------------------- */
  hero3d: {
    modelSrc: 'assets/models/tub.mesh.js',
    labelSrc: 'assets/models/label.tex.js',
    threeSrc: 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js',
    /* Below this viewport width the 3D stage never initialises and the static
       poster is used instead. Set low enough that phones DO get the 3D: the
       people this prototype is shown to open links on a phone, and the hero is
       the most persuasive thing on the page — hiding it there defeats the
       point. The payload is modest (three.js ~150 KB gzipped, mesh ~137 KB,
       label ~110 KB) and only 7,360 triangles, which any recent phone renders
       comfortably. */
    minViewportWidth: 320,   // 320 = the narrowest phone still in use

    /* …but not at any cost. On a metered or very slow connection the poster
       stays and nothing is downloaded. */
    skipOnSlowConnection: true,
  },

  storageKey: 'suppex.cart.v1',
};
