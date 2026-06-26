/**
 * Asset.js - Asset management and tracking
 * Handles asset lifecycle, versioning, and public records
 */

const Asset = {
  /**
   * Asset statuses
   */
  statuses: {
    DRAFT: 'Draft',
    PROPOSED: 'Proposal',
    DEVELOPING: 'Development',
    DEPLOYED: 'Asset',
    ARCHIVED: 'Archived'
  },

  /**
   * Asset quality tiers
   */
  quality: {
    ALPHA: 'Alpha',
    BETA: 'Beta',
    A_PLUS: 'A+',
    A_PLUS_PLUS: 'A++'
  },

  /**
   * Initialize Asset module
   */
  init: function() {
    this.setupListeners();
    App.debug('Asset initialized');
  },

  /**
   * Setup event listeners
   */
  setupListeners: function() {
    document.addEventListener('ideaLocked', (e) => this.onIdeaLocked(e));
    document.addEventListener('assetCreated', (e) => this.onAssetCreated(e));
  },

  /**
   * Create asset from idea
   */
  createAsset: function(idea) {
    const asset = {
      id: this.generateId(),
      title: idea.title || 'Untitled',
      originator: idea.originator || null,
      description: idea.description || '',
      status: this.statuses.DRAFT,
      quality: this.quality.ALPHA,
      version: '1.0.0',
      contributors: [idea.originator].filter(Boolean),
      attribution: idea.attribution || {},
      created: new Date().toISOString(),
      modified: new Date().toISOString(),
      parent: 'AJF & Co.',
      tags: ['Core System'],
      linkedIssue: null,
      publicRecord: true
    };

    return asset;
  },

  /**
   * Generate unique asset ID
   */
  generateId: function() {
    return 'asset_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  },

  /**
   * Update asset status
   */
  updateStatus: function(assetId, newStatus) {
    if (!Object.values(this.statuses).includes(newStatus)) {
      throw new Error(`Invalid status: ${newStatus}`);
    }

    const record = {
      assetId: assetId,
      previousStatus: null,
      newStatus: newStatus,
      timestamp: new Date().toISOString(),
      actor: 'system'
    };

    this.recordChange(record);
    return record;
  },

  /**
   * Update asset version
   */
  updateVersion: function(assetId, versionType = 'patch') {
    // versionType: 'major', 'minor', 'patch'
    const [major, minor, patch] = this.parseVersion('1.0.0');

    let newVersion;
    switch (versionType) {
      case 'major':
        newVersion = `${major + 1}.0.0`;
        break;
      case 'minor':
        newVersion = `${major}.${minor + 1}.0`;
        break;
      case 'patch':
      default:
        newVersion = `${major}.${minor}.${patch + 1}`;
    }

    return newVersion;
  },

  /**
   * Parse version string
   */
  parseVersion: function(versionString) {
    return versionString.split('.').map(Number);
  },

  /**
   * Add contributor to asset
   */
  addContributor: function(assetId, contributor) {
    return {
      assetId: assetId,
      contributor: contributor,
      timestamp: new Date().toISOString(),
      action: 'contributor_added'
    };
  },

  /**
   * Record asset change
   */
  recordChange: function(change) {
    try {
      const changes = JSON.parse(localStorage.getItem('asset_changes') || '[]');
      changes.push(change);
      localStorage.setItem('asset_changes', JSON.stringify(changes));
    } catch (error) {
      console.error('Asset change record error:', error);
    }
  },

  /**
   * Get asset change history
   */
  getChangeHistory: function(assetId) {
    try {
      const changes = JSON.parse(localStorage.getItem('asset_changes') || '[]');
      return changes.filter(c => c.assetId === assetId);
    } catch (error) {
      console.error('Asset history retrieval error:', error);
      return [];
    }
  },

  /**
   * Handle idea locked event
   */
  onIdeaLocked: function(event) {
    const { detail: idea } = event;
    const asset = this.createAsset(idea);
    this.storeAsset(asset);

    const event2 = new CustomEvent('assetCreated', { detail: asset });
    document.dispatchEvent(event2);
  },

  /**
   * Handle asset created event
   */
  onAssetCreated: function(event) {
    const { detail: asset } = event;
    App.debug('Asset created', asset);
  },

  /**
   * Store asset
   */
  storeAsset: function(asset) {
    try {
      const assets = JSON.parse(localStorage.getItem('ipmdb_assets') || '[]');
      assets.push(asset);
      localStorage.setItem('ipmdb_assets', JSON.stringify(assets));
    } catch (error) {
      console.error('Asset storage error:', error);
    }
  },

  /**
   * Retrieve asset
   */
  getAsset: function(assetId) {
    try {
      const assets = JSON.parse(localStorage.getItem('ipmdb_assets') || '[]');
      return assets.find(a => a.id === assetId);
    } catch (error) {
      console.error('Asset retrieval error:', error);
      return null;
    }
  },

  /**
   * List all assets
   */
  listAssets: function() {
    try {
      return JSON.parse(localStorage.getItem('ipmdb_assets') || '[]');
    } catch (error) {
      console.error('Asset list retrieval error:', error);
      return [];
    }
  },

  /**
   * Build public record display
   */
  buildPublicRecord: function(asset) {
    return {
      id: asset.id,
      title: asset.title,
      status: asset.status,
      quality: asset.quality,
      version: asset.version,
      created: asset.created,
      modified: asset.modified,
      contributors: asset.contributors,
      parent: asset.parent,
      tags: asset.tags,
      publicRecord: asset.publicRecord
    };
  }
};

// Initialize Asset
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => Asset.init());
} else {
  Asset.init();
}
