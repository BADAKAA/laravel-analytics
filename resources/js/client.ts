/**
 * Analytics Tracking Client
 * 
 * Minimal client-side tracking script for external sites.
 * Simply reports "This page was visited" to the analytics server.
 * All session management is handled server-side.
 * 
 * Usage:
 *   <script src="https://{your-domain.com}/client.js?site_id=123"></script>
 */

type PageviewPayload = {
  site_id: number;
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
  private siteId: number | null = null;
  private lastTrackedUrl: string | null = null;
  private visitStartedAtMs: number;
  private pendingTrackTimeoutId: number | null = null;

  constructor() {
    this.visitStartedAtMs = Date.now();
    this.apiEndpoint = this.getScriptOrigin() + '/api/pageview';

    this.siteId = this.extractSiteId();
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
      setTimeout(() => this.scheduleTrackPageview(), 0);
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
    const elapsedMs = Date.now() - this.visitStartedAtMs;

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
  private extractSiteId(): number | null {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return null;
    const url = new URL(scriptTag.src);
    const siteIdParam = url.searchParams.get('site_id');
    if (!siteIdParam) return null;
    const siteId = parseInt(siteIdParam, 10);
    if (isNaN(siteId)) return null;
    return siteId;
  }

  private getScriptOrigin(): string {
    const scriptTag = document.currentScript as HTMLScriptElement | null;
    if (!scriptTag?.src) return window.location.origin;

    const scriptUrl = new URL(scriptTag.src, window.location.href);
    return scriptUrl.origin;
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

  private trackPageview(): void {
    if (!this.siteId) return;

    const currentUrl = this.getCurrentUrl();
    if (this.lastTrackedUrl === currentUrl) return;

    this.lastTrackedUrl = currentUrl;

    const payload = this.buildPayload();

    // Use sendBeacon if available (more reliable for unload events)
    if (navigator.sendBeacon) {
      navigator.sendBeacon(this.apiEndpoint, JSON.stringify(payload));
      return;
    }
    // Fallback to fetch with keepalive
    fetch(this.apiEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
      keepalive: true,
    }).catch(() => {
      // Silently fail
    });
  }
}

new AnalyticsClient();
