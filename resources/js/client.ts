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

class AnalyticsClient {
    private MIN_VISIT_SECONDS = 2; 
    private apiEndpoint: string;
    private siteId: number | null = null;

  constructor() {
    this.apiEndpoint = this.getScriptOrigin() + '/api/pageview';

    this.siteId = this.extractSiteId();
    if (!this.siteId) {
        console.warn('site_id not found in script src, analytics disabled');
        return;
    }

    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => this.trackPageview(), this.MIN_VISIT_SECONDS * 1000);
    });
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

  /**
   * Build pageview payload
   */
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

  /**
   * Send pageview to API
   */
  private trackPageview(): void {
    if (!this.siteId) return;

    const payload = this.buildPayload();

    // Use sendBeacon if available (more reliable for unload events)
    if (navigator.sendBeacon) {
      navigator.sendBeacon(this.apiEndpoint, JSON.stringify(payload));
    } else {
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
}

new AnalyticsClient();
