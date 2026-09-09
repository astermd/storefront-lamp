/**
 * Extracted from the static design mockup, with one deliberate departure:
 * the cart's local DOM mutation (removing a card, rewriting a quantity
 * number) was removed, because the cart is server-owned now and its
 * controls submit forms instead. Everything else here is the mockup's
 * script unmodified.
 *
 * The mockup is not part of this repository, so this file is the source
 * now — edit it directly.
 */
(function () {
  'use strict';

  /* ---------- Mobile nav toggle ---------- */
  var menuBtn = document.getElementById('mobile-menu-btn');
  var menuPanel = document.getElementById('mobile-menu');
  var iconOpen = document.getElementById('icon-menu-open');
  var iconClose = document.getElementById('icon-menu-close');

  if (menuBtn && menuPanel) {
    menuBtn.addEventListener('click', function () {
      var isOpen = menuBtn.getAttribute('aria-expanded') === 'true';
      menuBtn.setAttribute('aria-expanded', String(!isOpen));
      menuPanel.classList.toggle('hidden', isOpen);
      iconOpen.classList.toggle('hidden', !isOpen);
      iconClose.classList.toggle('hidden', isOpen);
    });

    menuPanel.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        menuBtn.setAttribute('aria-expanded', 'false');
        menuPanel.classList.add('hidden');
        iconOpen.classList.remove('hidden');
        iconClose.classList.add('hidden');
      });
    });
  }

  /* ---------- FAQ accordion (single-open) ---------- */
  var faqAccordion = document.getElementById('faq-accordion');
  if (faqAccordion) {
    var faqItems = Array.prototype.slice.call(faqAccordion.querySelectorAll('.faq-item'));

    function setFaqState(item, open) {
      var trigger = item.querySelector('.faq-trigger');
      var panel = item.querySelector('.faq-panel');
      var chevron = item.querySelector('.faq-chevron');
      var question = item.querySelector('.faq-question');

      item.setAttribute('data-open', String(open));
      trigger.setAttribute('aria-expanded', String(open));
      chevron.classList.toggle('rotate-180', open);

      if (open) {
        panel.style.maxHeight = panel.scrollHeight + 'px';
        chevron.classList.remove('text-heading');
        chevron.classList.add('text-primary');
        question.classList.remove('text-heading');
        question.classList.add('text-primary');
      } else {
        panel.style.maxHeight = '0px';
        chevron.classList.remove('text-primary');
        chevron.classList.add('text-heading');
        question.classList.remove('text-primary');
        question.classList.add('text-heading');
      }
    }

    faqItems.forEach(function (item) {
      var trigger = item.querySelector('.faq-trigger');
      var initiallyOpen = item.getAttribute('data-open') === 'true';
      setFaqState(item, initiallyOpen);

      trigger.addEventListener('click', function () {
        var willOpen = item.getAttribute('data-open') !== 'true';
        faqItems.forEach(function (other) {
          if (other !== item) setFaqState(other, false);
        });
        setFaqState(item, willOpen);
      });
    });

    window.addEventListener('resize', function () {
      faqItems.forEach(function (item) {
        if (item.getAttribute('data-open') === 'true') {
          item.querySelector('.faq-panel').style.maxHeight =
            item.querySelector('.faq-panel').scrollHeight + 'px';
        }
      });
    });
  }

  /* ---------- Weight-loss promo banner carousel (chrome only: single designed slide) ---------- */
  var promoDotsWrap = document.getElementById('promo-dots');
  var promoTrack = document.getElementById('promo-track');
  if (promoDotsWrap) {
    var promoDots = Array.prototype.slice.call(promoDotsWrap.querySelectorAll('[data-promo-dot]'));
    var promoIndex = 0;

    function renderPromoDots() {
      promoDots.forEach(function (dot, i) {
        var active = i === promoIndex;
        dot.classList.toggle('bg-white', active);
        dot.classList.toggle('bg-black/10', !active);
        dot.setAttribute('aria-selected', String(active));
      });
    }

    function goToPromo(index) {
      var next = (index + promoDots.length) % promoDots.length;
      if (next === promoIndex) return;
      promoIndex = next;
      renderPromoDots();
      // Only one slide is designed today, so there's no different content to swap
      // in — this brief fade is the visible confirmation that the click registered.
      if (promoTrack) {
        promoTrack.style.opacity = '0';
        window.setTimeout(function () {
          promoTrack.style.opacity = '1';
        }, 150);
      }
    }

    promoDots.forEach(function (dot, i) {
      dot.addEventListener('click', function () { goToPromo(i); });
    });

    var promoPrev = document.querySelector('[data-promo-prev]');
    var promoNext = document.querySelector('[data-promo-next]');
    if (promoPrev) promoPrev.addEventListener('click', function () { goToPromo(promoIndex - 1); });
    if (promoNext) promoNext.addEventListener('click', function () { goToPromo(promoIndex + 1); });
  }

  /* ---------- Single-select button groups (product dosage / plan / gallery thumbs) ---------- */
  document.querySelectorAll('[data-select-group]').forEach(function (group) {
    var options = Array.prototype.slice.call(group.querySelectorAll('[data-select-option]'));
    options.forEach(function (option) {
      option.addEventListener('click', function () {
        options.forEach(function (other) { other.setAttribute('aria-pressed', 'false'); });
        option.setAttribute('aria-pressed', 'true');

        // Gallery thumbnails additionally swap the main product image.
        var mainImage = document.getElementById('product-main-image');
        var fullSrc = option.getAttribute('data-full-src');
        if (mainImage && fullSrc) {
          mainImage.src = fullSrc;
          mainImage.alt = option.getAttribute('data-full-alt') || mainImage.alt;
        }
      });
    });
  });

  /* ---------- Sticky product gallery: end-of-scroll gradient ---------- */
  var productSection = document.getElementById('product-section');
  var productGallery = document.getElementById('product-gallery');
  var galleryGradient = document.getElementById('gallery-end-gradient');
  if (productSection && productGallery && galleryGradient) {
    var galleryTicking = false;

    function updateGalleryGradient() {
      galleryTicking = false;
      // The gallery is about to unstick once its own bottom edge reaches the
      // bottom of the section it's pinned within — that's the "end of scroll"
      // moment the gradient should cue.
      var galleryBottom = productGallery.getBoundingClientRect().bottom;
      var sectionBottom = productSection.getBoundingClientRect().bottom;
      var nearEnd = sectionBottom - galleryBottom < 40;
      galleryGradient.classList.toggle('opacity-100', nearEnd);
    }

    function onGalleryScroll() {
      if (!galleryTicking) {
        galleryTicking = true;
        window.requestAnimationFrame(updateGalleryGradient);
      }
    }

    window.addEventListener('scroll', onGalleryScroll);
    window.addEventListener('resize', onGalleryScroll);
    updateGalleryGradient();
  }

  /* ---------- Testimonials carousel ---------- */
  var testimonialTrack = document.getElementById('testimonial-track');
  if (testimonialTrack) {
    var testimonialPrev = document.querySelector('[data-testimonial-prev]');
    var testimonialNext = document.querySelector('[data-testimonial-next]');

    function scrollTestimonials(direction) {
      var card = testimonialTrack.querySelector('figure');
      var cardStep = card ? card.getBoundingClientRect().width + 24 : testimonialTrack.clientWidth;
      // Two testimonials sit side by side from the `sm` breakpoint up (see their
      // `sm:basis-[calc(50%-12px)]` sizing), so slide a full pair at once there
      // instead of exposing one mismatched card at a time.
      var cardsPerView = window.innerWidth >= 640 ? 2 : 1;
      testimonialTrack.scrollBy({ left: direction * cardStep * cardsPerView, behavior: 'smooth' });
    }

    if (testimonialPrev) testimonialPrev.addEventListener('click', function () { scrollTestimonials(-1); });
    if (testimonialNext) testimonialNext.addEventListener('click', function () { scrollTestimonials(1); });

    function updateTestimonialNav() {
      var maxScrollLeft = testimonialTrack.scrollWidth - testimonialTrack.clientWidth;
      if (testimonialPrev) testimonialPrev.disabled = testimonialTrack.scrollLeft <= 1;
      if (testimonialNext) testimonialNext.disabled = testimonialTrack.scrollLeft >= maxScrollLeft - 1;
    }

    testimonialTrack.addEventListener('scroll', updateTestimonialNav);
    window.addEventListener('resize', updateTestimonialNav);
    updateTestimonialNav();
  }

  /* ---------- Cart dropdown ---------- */
  var cartBtn = document.getElementById('cart-btn');
  var cartPanel = document.getElementById('cart-panel');

  if (cartBtn && cartPanel) {
    function closeCartPanel() {
      cartBtn.setAttribute('aria-expanded', 'false');
      cartPanel.classList.add('hidden');
    }

    cartBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      var isOpen = cartBtn.getAttribute('aria-expanded') === 'true';
      cartBtn.setAttribute('aria-expanded', String(!isOpen));
      cartPanel.classList.toggle('hidden', isOpen);
    });

    document.addEventListener('click', function (event) {
      if (!cartPanel.classList.contains('hidden') && !cartPanel.contains(event.target)) {
        closeCartPanel();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeCartPanel();
    });
  }
})();
