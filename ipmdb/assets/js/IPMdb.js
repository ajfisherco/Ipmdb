/* ==========================================================
   IPMdb Global JavaScript v1.0
   ========================================================== */

(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initMenu();
    initFade();
    initOtherFields();
    initCopyButtons();
  });

  function initMenu() {
    const menuButton = document.querySelector("[data-menu-button]");
    const menuPanel = document.querySelector("[data-menu-panel]");

    if (!menuButton || !menuPanel) return;

    menuButton.addEventListener("click", function () {
      const isOpen = menuPanel.classList.toggle("open");
      menuButton.setAttribute("aria-expanded", String(isOpen));
    });
  }

  function initFade() {
    const items = document.querySelectorAll(".fade");

    if (!items.length) return;

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("show");
        }
      });
    }, { threshold: 0.12 });

    items.forEach(function (item) {
      observer.observe(item);
    });
  }

  function initOtherFields() {
    const selects = document.querySelectorAll("[data-other-select]");

    selects.forEach(function (select) {
      const targetSelector = select.getAttribute("data-other-select");
      const target = document.querySelector(targetSelector);

      if (!target) return;

      function syncOtherField() {
        const value = String(select.value || "").toLowerCase();
        const shouldShow = value === "other";

        target.classList.toggle("hidden", !shouldShow);

        const input = target.querySelector("input, textarea, select");
        if (input) {
          input.required = shouldShow;
        }
      }

      select.addEventListener("change", syncOtherField);
      syncOtherField();
    });
  }

  function initCopyButtons() {
    const buttons = document.querySelectorAll("[data-copy]");

    buttons.forEach(function (button) {
      button.addEventListener("click", async function () {
        const value = button.getAttribute("data-copy");

        if (!value) return;

        try {
          await navigator.clipboard.writeText(value);
          flashButton(button, "Copied");
        } catch (error) {
          flashButton(button, "Copy failed");
        }
      });
    });
  }

  function flashButton(button, message) {
    const original = button.textContent;

    button.textContent = message;

    window.setTimeout(function () {
      button.textContent = original;
    }, 1400);
  }
})();