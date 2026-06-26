# IPM.db Production Deployment

## Overview

This is the production-ready deployment package for IPM.db, the Intellectual Property Management database system. The package contains all necessary static assets, stylesheets, and JavaScript modules organized for clean deployment to Webnames (Plesk) hosting.

## Deployment Target

**Primary:** `https://ajfisherco.com/ipmdb/`

**Directory:** `httpdocs/ipmdb/` (Plesk file manager)

## Directory Structure

```
webnames-export/ipmdb/
├── index.html              Main entry point (DAD gateway)
├── css/
│   ├── styles.css         Core styles & variables
│   ├── desktop.css        Desktop-specific responsive styles
│   └── mobile.css         Mobile-first responsive styles
├── js/
│   ├── app.js             Core utilities & initialization
│   ├── ipmdb.js           IPM.db domain logic & I2A workflow
│   ├── dad.js             DAD contribution flow & payments
│   └── asset.js           Asset management & versioning
├── api/                   API placeholder for future endpoints
├── database/
│   └── schema.sql        Database schema (asset ledger)
├── assets/                External assets (images, icons)
│   └── ajfco-link-mark.svg Parent company mark
└── dad/                   DAD subsite (see separate deploy)
```

## Features

✅ **Responsive Design**
- Mobile-first approach
- Desktop enhancements (1024px+, 1440px+)
- Touch-friendly buttons and interactive elements

✅ **Separated Source Files**
- Modular CSS (styles + desktop + mobile)
- Modular JavaScript (app + ipmdb + dad + asset)
- No inline styles or scripts

✅ **Production Ready**
- Optimized asset loading
- Fallback patterns for JavaScript
- localStorage for client-side state
- Event-driven architecture

✅ **Accessibility**
- Semantic HTML
- ARIA labels where needed
- Color contrast compliance
- Keyboard navigation support

## Deployment Steps

### 1. Access Plesk File Manager

```
1. Log in to Plesk panel
2. Navigate to Files
3. Open httpdocs folder
4. Create or open 'ipmdb' folder
```

### 2. Upload Files

Copy entire `webnames-export/ipmdb/` contents into `httpdocs/ipmdb/`:

```
httpdocs/
└── ipmdb/
    ├── index.html
    ├── css/
    ├── js/
    ├── api/
    ├── database/
    ├── assets/
    └── dad/
```

### 3. Verify Directory Permissions

- Files: `644` (readable by web server)
- Directories: `755` (readable and executable)

Please note: Plesk usually handles this automatically.

### 4. Test URLs

**Homepage:**
```
https://ajfisherco.com/ipmdb/
https://ajfisherco.com/ipmdb/index.html
```

**DAD Page:**
```
https://ajfisherco.com/ipmdb/dad/
```

## Pre-Launch Checklist

- [ ] Page loads without 404 errors
- [ ] Desktop layout: content fills most screen width
- [ ] Mobile layout: content stacks cleanly (iPhone/Android)
- [ ] Buttons are clickable and styled correctly
- [ ] Links work (internal and external)
- [ ] Images/SVGs load correctly
- [ ] Responsive breakpoints work at: 480px, 768px, 1024px, 1440px
- [ ] JavaScript console shows no errors
- [ ] localStorage works (open DevTools → Application → Local Storage)
- [ ] E-transfer copy functionality works
- [ ] DAD contribution buttons open correctly
- [ ] GitHub ledger link opens repository issues
- [ ] AJF & Co. parent link works
- [ ] Footer links are valid
- [ ] Page is mobile-friendly (test with DevTools)
- [ ] No mixed content warnings (all HTTPS)

## Performance Checklist

- [ ] HTML loads in <1s
- [ ] CSS loads without blocking render
- [ ] JavaScript loads asynchronously
- [ ] Images are optimized
- [ ] No console errors or warnings
- [ ] Network tab shows <10 requests
- [ ] Total size <500KB

## Browser Compatibility

✅ Chrome/Edge 90+
✅ Firefox 88+
✅ Safari 14+
✅ Mobile Safari (iOS 14+)
✅ Chrome Mobile

## Rollback Plan

If deployment fails:

1. **Quick Rollback:** Use Plesk file manager to restore previous `index.html` and `css/` folder
2. **Full Rollback:** Restore entire `ipmdb/` directory from backup
3. **Cache Clear:** Purge CloudFlare cache if enabled

### Backup Location

Maintain backups before each deploy:

```
/httpdocs/ipmdb.backup-YYYY-MM-DD/
```

## Configuration

### Square Payment Links

Located in `js/dad.js`:

```javascript
config: {
  squareCardUrl: 'https://square.link/u/EcyDVlU3?src=sheet',
  squareQrUrl: 'https://square.link/u/EcyDVlU3?src=qr',
  interacEmail: 'ajfisherco@gmail.com'
}
```

Update if payment links change.

### GitHub Integration

Located in `js/ipmdb.js`:

```javascript
config: {
  githubRepo: 'ajfisherco/Ipmdb',
  githubIssuesUrl: 'https://github.com/ajfisherco/Ipmdb/issues'
}
```

Issues are prefilled and opened in new window.

## Local Development

To test locally before deployment:

```bash
# Start local server (Python 3)
python -m http.server 8000

# Or (Node.js)
npx http-server

# Visit: http://localhost:8000/webnames-export/ipmdb/
```

## Monitoring

After deployment, monitor:

1. **Page loads:** Check Plesk analytics
2. **Errors:** Review browser console in production (use external monitoring)
3. **Performance:** Monitor Core Web Vitals
4. **Availability:** Set up uptime monitoring

## Related Pages

- DAD homepage: `/dad/index.html`
- LOCK IDEA form: `/lock-idea.html` (root level)
- Ledger/Issues: GitHub issues in `ajfisherco/Ipmdb`
- Public record: Tracked through GitHub

## Support

- **Build Issues:** Check GitHub repository
- **Deployment Issues:** Contact Webnames/Plesk support
- **Functionality Issues:** Create GitHub issue with details

## Version

**Current:** v1.0.0 (June 2026)

**Last Updated:** 2026-06-26

**Deployed By:** Copilot Agent

---

## Quick Reference

### File Types

- **HTML:** Entry point & routing
- **CSS:** Responsive styles (3 files for separation)
- **JavaScript:** Modular domain logic (4 modules)
- **SQL:** Optional database schema
- **SVG:** Lightweight graphics (parent mark)

### Key URLs

```
https://ajfisherco.com/ipmdb/              Main page
https://ajfisherco.com/ipmdb/dad/          DAD contribution
https://ajfisherco.com/ipmdb/css/          CSS assets
https://ajfisherco.com/ipmdb/js/           JavaScript modules
https://api.github.com/repos/ajfisherco/Ipmdb/issues   Public ledger
```

### Environment Variables

None required. All configuration is in JavaScript files.

### Cache Strategy

- HTML: No cache (always fresh)
- CSS/JS: Cache 1 year (versioned on changes)
- Images/SVG: Cache 1 month

Configure in `.htaccess` or Plesk settings.
