/**
 * Analytics Tracking Client
 * 
 * Minimal client-side tracking script for external sites.
 * Simply reports "This page was visited" to the analytics server.
 * All session management is handled server-side.
 * 
 * Usage:
 *   <script src="https://{your-domain.com}/client.js?site_id=SITE_PUBLIC_ID"></script>
 *   <script src="https://{your-domain.com}/client.js?site_id=SITE_PUBLIC_ID#/analytics-forward"></script>
 *   <script src="https://{your-domain.com}/client.js?site_id=SITE_PUBLIC_ID&csrf=CSRF_TOKEN#/analytics-forward"></script>
 */

type PageviewPayload = {
  site_id: string;
  pathname: string;
  hostname: string;
  referrer?: string;
  screen_width: number;
  utm_source?: string;
  utm_medium?: string;
  utm_campaign?: string;
  utm_content?: string;
  utm_term?: string;
};

type AnalyticsTracker = {
  trackPageview: () => void;
};

export { };

declare global {
  interface Window {
    analyticsClient?: AnalyticsTracker;
    trackAnalyticsPageview?: () => void;
  }
}

class AnalyticsClient {
  private MIN_VISIT_SECONDS = 2;
  private apiEndpoint: string;
  private targetEndpoint: string;
  private siteId: string | null = null;
  private csrfToken: string | null = null;
  private lastTrackedUrl: string | null = null;
  private visitStartedAt: number;
  private pendingTrackTimeoutId: number | null = null;

  constructor() {
    this.visitStartedAt = Date.now();
    this.targetEndpoint = this.getScriptOrigin() + '/api/v';
    this.apiEndpoint = this.targetEndpoint;

    const forwardEndpoint = this.extractForwardEndpointFromHash();
    if (forwardEndpoint) this.apiEndpoint = forwardEndpoint;

    this.siteId = this.extractSiteId();
    this.csrfToken = this.extractCsrfToken();
    if (!this.siteId) {
      console.warn('site_id not found in script src, analytics disabled');
      return;
    }

    this.setupSpaAutoTracking();
    this.scheduleTrackPageview();

    // Expose a global API so SPA routers can trigger pageviews on navigation.
    window.analyticsClient = {
      trackPageview: () => this.scheduleTrackPageview(),
    };
    window.trackAnalyticsPageview = () => this.scheduleTrackPageview();
  }

  private setupSpaAutoTracking(): void {
    const emitNavigation = () => {
      window.dispatchEvent(new Event('analytics:navigate'));
    };

    const originalPushState = history.pushState.bind(history);
    history.pushState = ((...args: Parameters<History['pushState']>) => {
      const result = originalPushState(...args);
      emitNavigation();
      return result;
    }) as History['pushState'];

    const originalReplaceState = history.replaceState.bind(history);
    history.replaceState = ((...args: Parameters<History['replaceState']>) => {
      const result = originalReplaceState(...args);
      emitNavigation();
      return result;
    }) as History['replaceState'];

    const onNavigate = () => {
      this.visitStartedAt = Date.now();
      this.scheduleTrackPageview();
    };

    window.addEventListener('analytics:navigate', onNavigate);
    window.addEventListener('popstate', onNavigate);
    window.addEventListener('hashchange', onNavigate);
  }

  private getCurrentUrl(): string {
    return window.location.href;
  }

  private scheduleTrackPageview(): void {
    const minVisitMs = this.MIN_VISIT_SECONDS * 1000;
    const elapsedMs = Date.now() - this.visitStartedAt;

    if (elapsedMs >= minVisitMs) {
      this.trackPageview();
      return;
    }

    if (this.pendingTrackTimeoutId !== null) return;

    const remainingMs = minVisitMs - elapsedMs;
    this.pendingTrackTimeoutId = window.setTimeout(() => {
      this.pendingTrackTimeoutId = null;
      this.trackPageview();
    }, remainingMs);
  }

  /**
   * Extract site_id from ?site_id= query parameter in script src
   */
  private extractSiteId(): string | null {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return null;
    const url = new URL(scriptTag.src);
    const siteIdParam = url.searchParams.get('site_id');
    if (!siteIdParam) return null;
    return siteIdParam;
  }

  private getScriptOrigin(): string {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return window.location.origin;

    const scriptUrl = new URL(scriptTag.src, window.location.href);
    return scriptUrl.origin;
  }

  private extractCsrfToken(): string | null {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return null;

    try {
      const scriptUrl = new URL(scriptTag.src, window.location.href);
      const csrf = scriptUrl.searchParams.get('csrf');
      return csrf ? csrf : null;
    } catch {
      return null;
    }
  }

  private extractForwardEndpointFromHash(): string | null {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return null;

    try {
      const scriptUrl = new URL(scriptTag.src, window.location.href);
      const hashValue = scriptUrl.hash.replace(/^#/, '').trim();
      if (!hashValue) return null;

      const candidate = `/${hashValue.replace(/^\/+/, '')}`;
      const forwardUrl = new URL(candidate, window.location.origin);

      if (forwardUrl.origin !== window.location.origin) {
        console.warn('forward hash must resolve to same-origin endpoint, ignoring');
        return null;
      }

      return forwardUrl.toString();
    } catch {
      console.warn('invalid forward hash, ignoring');
      return null;
    }
  }

  private extractUtmParams(): Record<string, string> {
    const params = new URLSearchParams(window.location.search);
    const utm: Record<string, string> = {};

    const utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    for (const key of utmKeys) {
      const value = params.get(key);
      if (value) utm[key] = value;
    }

    return utm;
  }

  private buildPayload(): PageviewPayload {
    const utm = this.extractUtmParams();

    return {
      site_id: this.siteId!,
      pathname: window.location.pathname,
      hostname: window.location.hostname,
      referrer: document.referrer || undefined,
      screen_width: window.innerWidth,
      ...utm,
    };
  }

  private buildFormPayload(payload: PageviewPayload): URLSearchParams {
    const params = new URLSearchParams();

    params.set('site_id', String(payload.site_id));
    params.set('pathname', payload.pathname);
    params.set('hostname', payload.hostname);
    params.set('target_endpoint', this.targetEndpoint);
    params.set('screen_width', String(payload.screen_width));

    if (payload.referrer) params.set('referrer', payload.referrer);
    if (payload.utm_source) params.set('utm_source', payload.utm_source);
    if (payload.utm_medium) params.set('utm_medium', payload.utm_medium);
    if (payload.utm_campaign) params.set('utm_campaign', payload.utm_campaign);
    if (payload.utm_content) params.set('utm_content', payload.utm_content);
    if (payload.utm_term) params.set('utm_term', payload.utm_term);
    if (this.csrfToken) params.set('_token', this.csrfToken);

    return params;
  }

  private shouldUseSendBeacon(): boolean {
    try {
      const endpointUrl = new URL(this.apiEndpoint, window.location.href);
      return endpointUrl.origin === window.location.origin;
    } catch {
      return false;
    }
  }

  private trackPageview(): void {
    if (!this.siteId) return;

    const currentUrl = this.getCurrentUrl();
    if (this.lastTrackedUrl === currentUrl) return;

    this.lastTrackedUrl = currentUrl;
    
    const payload = this.buildPayload();
    const formPayload = this.buildFormPayload(payload);
    // Avoid third-party beacon requests because many blockers match $ping,3p.
    if (navigator.sendBeacon && this.shouldUseSendBeacon()) {
      const queued = navigator.sendBeacon(this.apiEndpoint, formPayload);
      if (queued) return;
    }

    // Fallback to fetch with keepalive
    fetch(this.apiEndpoint, {
      method: 'POST',
      body: formPayload,
      keepalive: true,
    }).catch(() => {
      // Silently fail
    });
  }
}

new AnalyticsClient();
