/**
 * IPMdb.js - Core IPM.db domain logic
 * Handles asset ledger, idea tracking, and I2A workflow
 */

const IPMdb = {
  /**
   * Configuration
   */
  config: {
    githubRepo: 'ajfisherco/Ipmdb',
    githubApiBase: 'https://api.github.com',
    githubIssuesUrl: 'https://github.com/ajfisherco/Ipmdb/issues'
  },

  /**
   * Initialize IPMdb module
   */
  init: function() {
    this.setupListeners();
    this.cacheElements();
    App.debug('IPMdb initialized');
  },

  /**
   * Cache DOM elements
   */
  cacheElements: function() {
    this.elements = {};
  },

  /**
   * Setup event listeners
   */
  setupListeners: function() {
    // Listen for idea lock events
    document.addEventListener('ideaLocked', (e) => this.onIdeaLocked(e));
    document.addEventListener('contributionMade', (e) => this.onContributionMade(e));
  },

  /**
   * Lock an idea - capture and record
   */
  lockIdea: function(idea) {
    const lock = {
      timestamp: new Date().toISOString(),
      title: idea.title || 'Untitled',
      originator: idea.originator || null,
      attribution: idea.attribution || {},
      status: 'Draft',
      version: '1.0',
      parent: 'AJF & Co.',
      related: ['IPMdb', 'Core System']
    };

    // Store locally
    this.storeLocally(lock);

    // Emit event
    const event = new CustomEvent('ideaLocked', { detail: lock });
    document.dispatchEvent(event);

    return lock;
  },

  /**
   * Generate GitHub issue markdown from idea
   */
  generateIssueMarkdown: function(idea) {
    const { title, originator, email, attribution = {} } = idea;
    
    let markdown = `# IPMdb — LOCK IDEA\n\n`;
    markdown += `Capture an idea before it disappears.\n\n---\n\n`;
    markdown += `## IDEA\n${title}\n\n---\n\n`;
    
    if (originator || email) {
      markdown += `## ORIGINATOR\n`;
      if (email) markdown += `Email: ${email}\n`;
      markdown += `\n---\n\n`;
    }

    if (Object.keys(attribution).length > 0) {
      markdown += `## ATTRIBUTION\n`;
      Object.entries(attribution).forEach(([key, value]) => {
        if (value) markdown += `${key}: ${value}\n`;
      });
      markdown += `\n---\n\n`;
    }

    markdown += `## ASSET LEDGER\n`;
    markdown += `Status: Draft\n`;
    markdown += `Version: 1.0\n`;
    markdown += `Parent: AJF & Co.\n`;
    markdown += `Related: IPMdb, Core System\n\n`;
    markdown += `---\n\n`;
    markdown += `## NOTICE\n`;
    markdown += `Submission into IPMdb records origin, contribution, and development history as part of a public intellectual property management process.`;

    return markdown;
  },

  /**
   * Open GitHub issue prefilled with idea
   */
  openGitHubIssue: function(idea) {
    const title = idea.title || 'IPMdb LOCK IDEA';
    const body = this.generateIssueMarkdown(idea);
    const labels = ['Draft', 'Core System'];

    const url = new URL(`${this.config.githubIssuesUrl}/new`);
    url.searchParams.set('title', title);
    url.searchParams.set('body', body);
    url.searchParams.set('labels', labels.join(','));

    window.open(url.toString(), '_blank', 'noopener,noreferrer');
  },

  /**
   * Handle idea locked event
   */
  onIdeaLocked: function(event) {
    const { detail: lock } = event;
    App.debug('Idea locked', lock);
    App.showToast('Idea locked and ready to track');
  },

  /**
   * Handle contribution made event
   */
  onContributionMade: function(event) {
    const { detail: contribution } = event;
    App.debug('Contribution recorded', contribution);
    App.showToast('Contribution recorded');
  },

  /**
   * Store idea locally (localStorage)
   */
  storeLocally: function(data) {
    try {
      const store = JSON.parse(localStorage.getItem('ipmdb_ideas') || '[]');
      store.push(data);
      localStorage.setItem('ipmdb_ideas', JSON.stringify(store));
      return true;
    } catch (error) {
      console.error('Storage error:', error);
      return false;
    }
  },

  /**
   * Retrieve stored ideas
   */
  getStoredIdeas: function() {
    try {
      return JSON.parse(localStorage.getItem('ipmdb_ideas') || '[]');
    } catch (error) {
      console.error('Retrieval error:', error);
      return [];
    }
  },

  /**
   * Build I2A journey status display
   */
  buildI2AStatus: function(idea) {
    return {
      stage: idea.status || 'Idea',
      progress: this.calculateProgress(idea),
      nextSteps: this.getNextSteps(idea)
    };
  },

  /**
   * Calculate progress percentage
   */
  calculateProgress: function(idea) {
    const stages = ['Idea', 'Draft', 'Proposal', 'Development', 'Asset', 'Deployed'];
    const currentIndex = stages.indexOf(idea.status || 'Idea');
    return Math.round((currentIndex / (stages.length - 1)) * 100);
  },

  /**
   * Get next steps in workflow
   */
  getNextSteps: function(idea) {
    const status = idea.status || 'Idea';
    const steps = {
      'Idea': ['Lock the idea', 'Record originator', 'Add attribution'],
      'Draft': ['Gather feedback', 'Refine proposal', 'Create assets'],
      'Proposal': ['Get approvals', 'Plan implementation', 'Set timeline'],
      'Development': ['Execute plan', 'Track progress', 'Record changes'],
      'Asset': ['Document results', 'Publish record', 'Archive version'],
      'Deployed': ['Monitor usage', 'Track improvements', 'Plan A++']
    };
    return steps[status] || [];
  }
};

// Initialize IPMdb
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => IPMdb.init());
} else {
  IPMdb.init();
}
