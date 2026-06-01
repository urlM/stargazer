import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

const APP_SHELL_SELECTOR = 'main.brand-shell';
const NEXT_PAGE_LINK_SELECTOR = '[data-next-page-link]';
const PREFETCH_DELAY_MS = 200;
const pageCache = new Map();

document.addEventListener('DOMContentLoaded', () => {
    initializePaginationPrefetch();
    window.addEventListener('popstate', handlePopState);
});

function initializePaginationPrefetch() {
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
        void prefetchPage(prefetchUrl);
    };

    nextPageLink.addEventListener('mouseenter', prefetchOnIntent, { once: true });
    nextPageLink.addEventListener('focus', prefetchOnIntent, { once: true });

    nextPageLink.addEventListener('click', (event) => {
        if (shouldBypassClick(event)) {
            return;
        }

        const cachedPage = pageCache.get(prefetchUrl.href);
        if (cachedPage?.status !== 'ready' || typeof cachedPage.html !== 'string') {
            return;
        }

        event.preventDefault();
        renderPageHtml(cachedPage.html, prefetchUrl, true);
    });
}

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
            void prefetchPage(prefetchUrl);
        }, { timeout: 1500 });

        return;
    }

    window.setTimeout(() => {
        void prefetchPage(prefetchUrl);
    }, PREFETCH_DELAY_MS);
}

async function prefetchPage(prefetchUrl) {
    const href = prefetchUrl.href;
    const cachedPage = pageCache.get(href);

    if (cachedPage?.status === 'ready') {
        return cachedPage.html;
    }

    if (cachedPage?.status === 'pending' && cachedPage.promise instanceof Promise) {
        return cachedPage.promise;
    }

    const request = fetch(href, {
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'prefetch',
        },
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Prefetch failed with HTTP ${response.status}`);
            }

            return response.text();
        })
        .then((html) => {
            pageCache.set(href, { status: 'ready', html });

            return html;
        })
        .catch((error) => {
            pageCache.delete(href);
            throw error;
        });

    pageCache.set(href, { status: 'pending', promise: request });

    return request;
}

function resolvePrefetchUrl(nextPageLink) {
    try {
        return new URL(nextPageLink.href, window.location.href);
    } catch {
        return null;
    }
}

function shouldBypassClick(event) {
    return event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey;
}

async function handlePopState() {
    const shell = document.querySelector(APP_SHELL_SELECTOR);
    if (!(shell instanceof HTMLElement)) {
        return;
    }

    const targetUrl = new URL(window.location.href);
    if (shouldSkipPrefetch(targetUrl)) {
        return;
    }

    const cachedPage = pageCache.get(targetUrl.href);
    if (cachedPage?.status === 'ready' && typeof cachedPage.html === 'string') {
        renderPageHtml(cachedPage.html, targetUrl, false);

        return;
    }

    try {
        const html = await prefetchPage(targetUrl);
        renderPageHtml(html, targetUrl, false);
    } catch {
        window.location.reload();
    }
}

function renderPageHtml(html, targetUrl, pushState) {
    const parser = new DOMParser();
    const nextDocument = parser.parseFromString(html, 'text/html');
    const currentShell = document.querySelector(APP_SHELL_SELECTOR);
    const nextShell = nextDocument.querySelector(APP_SHELL_SELECTOR);

    if (!(currentShell instanceof HTMLElement) || !(nextShell instanceof HTMLElement)) {
        window.location.assign(targetUrl.href);

        return;
    }

    currentShell.innerHTML = nextShell.innerHTML;
    document.title = nextDocument.title || document.title;

    if (pushState) {
        window.history.pushState(null, '', targetUrl.href);
    }

    window.scrollTo({ top: 0, behavior: 'auto' });
    initializePaginationPrefetch();
}
