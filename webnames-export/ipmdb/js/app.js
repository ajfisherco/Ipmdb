/**
 * App.js - Core application utilities and initialization
 * Shared utilities for DOM manipulation, events, and storage
 */

const App = {
  /**
   * Initialize the application
   */
  init: function() {
    this.setupEventListeners();
    this.checkEnvironment();
    console.log('App initialized', { timestamp: new Date().toISOString() });
  },

  /**
   * Setup global event listeners
   */
  setupEventListeners: function() {
    // Handle smooth scroll behavior
    document.addEventListener('click', this.handleLinkClick.bind(this));
    
    // Handle form submissions
    document.addEventListener('submit', this.handleFormSubmit.bind(this));
  },

  /**
   * Handle internal link clicks
   */
  handleLinkClick: function(event) {
    const link = event.target.closest('a');
    if (!link) return;

    const href = link.getAttribute('href');
    if (href && href.startsWith('#')) {
      event.preventDefault();
      const target = document.querySelector(href);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth' });
      }
    }
  },

  /**
   * Handle form submissions
   */
  handleFormSubmit: function(event) {
    // Form handling delegated to specific modules
  },

  /**
   * Utility: Format currency (CAD)
   */
  formatCurrency: function(amount) {
    return amount.toLocaleString('en-CA', {
      style: 'currency',
      currency: 'CAD',
      minimumFractionDigits: 2
    });
  },

  /**
   * Utility: Copy to clipboard
   */
  copyToClipboard: async function(text) {
    try {
      await navigator.clipboard.writeText(text);
      return { success: true, message: 'Copied to clipboard' };
    } catch (error) {
      console.error('Clipboard copy failed:', error);
      return { success: false, message: 'Copy failed' };
    }
  },

  /**
   * Utility: Show notification toast
   */
  showToast: function(message, duration = 3000) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      bottom: 24px;
      left: 24px;
      background: var(--green);
      color: white;
      padding: 12px 20px;
      border-radius: 8px;
      font-weight: 600;
      z-index: 9999;
      animation: slideUp 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
      toast.style.animation = 'slideDown 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  },

  /**
   * Utility: Get URL parameters
   */
  getQueryParam: function(name) {
    const params = new URLSearchParams(window.location.search);
    return params.get(name);
  },

  /**
   * Utility: Build URL with parameters
   */
  buildUrl: function(baseUrl, params = {}) {
    const url = new URL(baseUrl, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        url.searchParams.set(key, value);
      }
    });
    return url.toString();
  },

  /**
   * Check environment and settings
   */
  checkEnvironment: function() {
    // Detect if running locally vs production
    const isLocal = window.location.hostname === 'localhost' || 
                    window.location.hostname === '127.0.0.1';
    window.ENV = {
      isLocal: isLocal,
      isProduction: !isLocal && window.location.protocol === 'https:',
      apiBase: isLocal ? 'http://localhost:3000/api' : '/api'
    };
  },

  /**
   * Log debug message
   */
  debug: function(label, data = {}) {
    if (window.ENV?.isLocal) {
      console.log(`[${label}]`, data);
    }
  }
};

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => App.init());
} else {
  App.init();
}
