import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

const NEXT_PAGE_LINK_SELECTOR = '[data-next-page-link]';
const PREFETCH_REL = 'prefetch';
const PREFETCH_DELAY_MS = 200;

document.addEventListener('DOMContentLoaded', () => {
    const nextPageLink = document.querySelector(NEXT_PAGE_LINK_SELECTOR);

    if (!(nextPageLink instanceof HTMLAnchorElement)) {
        return;
    }

    const prefetchUrl = resolvePrefetchUrl(nextPageLink);
    if (prefetchUrl === null || shouldSkipPrefetch(prefetchUrl)) {
        return;
    }

    queueIdlePrefetch(prefetchUrl);

    const prefetchOnIntent = () => {
        prefetchPage(prefetchUrl);
    };

    nextPageLink.addEventListener('mouseenter', prefetchOnIntent, { once: true });
    nextPageLink.addEventListener('focus', prefetchOnIntent, { once: true });
});

function shouldSkipPrefetch(prefetchUrl) {
    const connection = navigator.connection ?? navigator.mozConnection ?? navigator.webkitConnection;
    if (connection?.saveData === true) {
        return true;
    }

    return prefetchUrl.origin !== window.location.origin;
}

function queueIdlePrefetch(prefetchUrl) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(() => {
            prefetchPage(prefetchUrl);
        }, { timeout: 1500 });

        return;
    }

    window.setTimeout(() => {
        prefetchPage(prefetchUrl);
    }, PREFETCH_DELAY_MS);
}

function prefetchPage(prefetchUrl) {
    const href = prefetchUrl.href;
    if (document.head.querySelector(`link[rel="${PREFETCH_REL}"][href="${href}"]`) !== null) {
        return;
    }

    const prefetchLink = document.createElement('link');
    prefetchLink.rel = PREFETCH_REL;
    prefetchLink.href = href;
    prefetchLink.as = 'document';
    document.head.append(prefetchLink);
}

function resolvePrefetchUrl(nextPageLink) {
    try {
        return new URL(nextPageLink.href, window.location.href);
    } catch {
        return null;
    }
}
