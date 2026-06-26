/**
 * DAD.js - Dollar A Day contribution flow
 * Handles payment methods, tracking, and contribution records
 */

const DAD = {
  /**
   * Configuration
   */
  config: {
    squareCardUrl: 'https://square.link/u/EcyDVlU3?src=sheet',
    squareQrUrl: 'https://square.link/u/EcyDVlU3?src=qr',
    interacEmail: 'ajfisherco@gmail.com',
    defaultAmounts: [1, 7, 30, 365],
    currency: 'CAD'
  },

  /**
   * Initialize DAD module
   */
  init: function() {
    this.setupElements();
    this.setupListeners();
    this.loadSavedContribution();
    App.debug('DAD initialized');
  },

  /**
   * Setup DOM elements
   */
  setupElements: function() {
    this.elements = {
      form: document.getElementById('dadForm'),
      amountInput: document.getElementById('dadAmount'),
      methodSelect: document.getElementById('paymentMethod'),
      summary: document.getElementById('dadSummary')
    };
  },

  /**
   * Setup event listeners
   */
  setupListeners: function() {
    if (this.elements.form) {
      this.elements.form.addEventListener('submit', (e) => this.handleSubmit(e));
    }

    if (this.elements.amountInput) {
      this.elements.amountInput.addEventListener('change', (e) => this.onAmountChange(e));
    }

    // Payment method handlers
    document.addEventListener('click', (e) => {
      if (e.target.matches('[data-payment-method]')) {
        this.handlePaymentMethod(e.target.dataset.paymentMethod);
      }
    });
  },

  /**
   * Validate contribution amount
   */
  validateAmount: function(amount) {
    const num = parseFloat(amount);
    if (!Number.isFinite(num) || num <= 0) {
      return { valid: false, error: 'Amount must be greater than $0' };
    }
    if (num > 100000) {
      return { valid: false, error: 'Amount cannot exceed $100,000' };
    }
    return { valid: true, amount: num };
  },

  /**
   * Handle amount change
   */
  onAmountChange: function(event) {
    const validation = this.validateAmount(event.target.value);
    if (validation.valid) {
      this.setAmount(validation.amount);
    } else {
      App.showToast(validation.error);
    }
  },

  /**
   * Set contribution amount
   */
  setAmount: function(amount) {
    this.currentAmount = amount;
    this.saveContribution();

    const formatted = App.formatCurrency(amount);
    const days = Math.floor(amount);

    // Update UI
    if (this.elements.summary) {
      this.elements.summary.innerHTML = `
        <p class="summary-label">CONTRIBUTION</p>
        <p class="amount">${formatted}</p>
        <p class="days-covered">DAYS COVERED: ${days}</p>
      `;
    }
  },

  /**
   * Handle payment method selection
   */
  handlePaymentMethod: function(method) {
    if (!this.currentAmount) {
      App.showToast('Please select an amount first');
      return;
    }

    switch (method) {
      case 'card':
        this.payByCard();
        break;
      case 'etransfer':
        this.payByETransfer();
        break;
      case 'qr':
        this.payByQR();
        break;
      default:
        App.showToast('Payment method not supported');
    }
  },

  /**
   * Pay by card (Square)
   */
  payByCard: function() {
    if (!this.validateSquareUrl(this.config.squareCardUrl)) {
      App.showToast('Card payment is not yet available');
      return;
    }

    const url = this.buildPaymentUrl(this.config.squareCardUrl, this.currentAmount);
    window.open(url, '_blank', 'noopener,noreferrer');
    this.recordPaymentAttempt('card', this.currentAmount);
  },

  /**
   * Pay by e-transfer
   */
  payByETransfer: async function() {
    const message = `DAD Contribution ${App.formatCurrency(this.currentAmount)} to ${this.config.interacEmail}`;
    const result = await App.copyToClipboard(message);

    if (result.success) {
      App.showToast('E-transfer details copied');
      this.recordPaymentAttempt('etransfer', this.currentAmount);
    } else {
      App.showToast('Copy failed. Send to: ' + this.config.interacEmail);
    }
  },

  /**
   * Pay by QR code
   */
  payByQR: function() {
    if (!this.validateSquareUrl(this.config.squareQrUrl)) {
      App.showToast('QR code is not yet available');
      return;
    }

    const url = this.buildPaymentUrl(this.config.squareQrUrl, this.currentAmount);
    window.open(url, '_blank', 'noopener,noreferrer');
    this.recordPaymentAttempt('qr', this.currentAmount);
  },

  /**
   * Build payment URL with amount
   */
  buildPaymentUrl: function(baseUrl, amount) {
    const url = new URL(baseUrl);
    url.searchParams.set('amount', amount.toString());
    return url.toString();
  },

  /**
   * Validate Square URL is live
   */
  validateSquareUrl: function(url) {
    return url && !url.includes('PASTE_') && /^https?\/\/.+/.test(url);
  },

  /**
   * Record payment attempt in ledger
   */
  recordPaymentAttempt: function(method, amount) {
    const record = {
      timestamp: new Date().toISOString(),
      method: method,
      amount: amount,
      status: 'initiated',
      currency: this.config.currency
    };

    // Store locally
    this.storePaymentRecord(record);

    // Emit event for external logging
    const event = new CustomEvent('contributionInitiated', { detail: record });
    document.dispatchEvent(event);
  },

  /**
   * Store payment record locally
   */
  storePaymentRecord: function(record) {
    try {
      const records = JSON.parse(localStorage.getItem('dad_payments') || '[]');
      records.push(record);
      localStorage.setItem('dad_payments', JSON.stringify(records));
    } catch (error) {
      console.error('Payment storage error:', error);
    }
  },

  /**
   * Get payment history
   */
  getPaymentHistory: function() {
    try {
      return JSON.parse(localStorage.getItem('dad_payments') || '[]');
    } catch (error) {
      console.error('Payment retrieval error:', error);
      return [];
    }
  },

  /**
   * Save contribution to localStorage
   */
  saveContribution: function() {
    if (this.currentAmount) {
      localStorage.setItem('dad_lastAmount', this.currentAmount.toString());
    }
  },

  /**
   * Load saved contribution from localStorage
   */
  loadSavedContribution: function() {
    const saved = localStorage.getItem('dad_lastAmount');
    if (saved) {
      this.setAmount(parseFloat(saved));
    }
  },

  /**
   * Handle form submission
   */
  handleSubmit: function(event) {
    event.preventDefault();
    App.showToast('Please select a payment method');
  }
};

// Initialize DAD
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => DAD.init());
} else {
  DAD.init();
}
