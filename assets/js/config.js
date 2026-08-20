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
     ready-made message and handed to Telegram (or WhatsApp), so the seller
     keeps receiving orders exactly where they already do — no gateway, no
     trust seal, no company registration needed to go live.
     Phase 2 switches `method` to 'gateway' and only the checkout handler
     changes; nothing else in the cart or catalogue is touched.             */
  ordering: {
    method: 'telegram',            // 'telegram' | 'whatsapp' | 'gateway'
    telegramUsername: 'suppex_shop',   // without the @
    whatsappNumber: '989121234567',    // country code, digits only
    /* Prepended to the generated order message. */
    intro: 'سلام، می‌خواستم این سفارش را ثبت کنم:',
  },

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
    /* Below this viewport width the 3D stage never initialises — mobile gets
       the static poster instead. Saves the download and the battery. */
    minViewportWidth: 900,
  },

  storageKey: 'suppex.cart.v1',
};
