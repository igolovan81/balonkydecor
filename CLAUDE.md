# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a PHP website hosted on [WEDOS](https://www.wedos.cz/) web hosting. The project name is **balonkydecor** (balloon decoration business site). There is no build step — files are deployed directly to the hosting server.

## Directory Structure

```
www/           # Web root (served by Apache)
  .htaccess    # Routing rules for subdomains and domain aliases
  index.html   # Default landing page (currently a WEDOS parking iframe)
  info.php     # PHP diagnostics page (phpinfo())
  domains/     # One subdirectory per domain alias (e.g. domains/example.com/)
  subdom/      # One subdirectory per subdomain (e.g. subdom/blog/)
session/       # PHP session storage (outside web root)
tmp/           # Temporary files (outside web root)
```

## Routing Logic (`.htaccess`)

Apache mod_rewrite handles two routing patterns:

- **Domain aliases**: Requests to `example.com/*` are internally rewritten to `www/domains/example.com/*` when that directory exists.
- **Subdomains**: Requests to `sub.domain.tld/*` are internally rewritten to `www/subdom/sub/*` when that directory exists.

To add a new subdomain: create `www/subdom/<name>/`.  
To add a new domain alias: create `www/domains/<full-domain>/`.

## Deployment

No CI/CD is configured. Changes are deployed manually via FTP/SFTP to the WEDOS server. The local repo mirrors what should be on the server under the hosting account root.
