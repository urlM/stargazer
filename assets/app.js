import './styles/app.css';

const FRAGMENT_SELECTOR = '[data-infinite-content]';
const LISTING_SELECTOR = '[data-infinite-scroll]';
const ROWS_SELECTOR = '[data-repository-rows]';
const ROW_SELECTOR = '[data-repository-id]';
const PAGINATION_SELECTOR = '[data-pagination]';
const SENTINEL_SELECTOR = '[data-infinite-sentinel]';
const STATUS_SELECTOR = '[data-infinite-status]';
const ERROR_SELECTOR = '[data-infinite-error]';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(LISTING_SELECTOR).forEach((listing) => {
        initializeInfiniteScroll(listing);
    });
});

function initializeInfiniteScroll(listing) {
    if (!('IntersectionObserver' in window)) {
        return;
    }

    const rowsContainer = listing.querySelector(ROWS_SELECTOR);
    const pagination = listing.querySelector(PAGINATION_SELECTOR);
    const sentinel = listing.querySelector(SENTINEL_SELECTOR);
    const status = listing.querySelector(STATUS_SELECTOR);
    const error = listing.querySelector(ERROR_SELECTOR);

    if (!(rowsContainer instanceof HTMLElement) || !(pagination instanceof HTMLElement) || !(sentinel instanceof HTMLElement)) {
        return;
    }

    const seenRepositoryIds = new Set(
        Array.from(rowsContainer.querySelectorAll(ROW_SELECTOR)).map(
            (row) => row.getAttribute('data-repository-id') ?? ''
        )
    );

    const throttleMs = Number.parseInt(listing.dataset.throttleMs ?? '400', 10);
    const fragmentParam = listing.dataset.fragmentParam ?? '_fragment';
    let nextPageUrl = pagination.dataset.nextPageUrl ?? '';
    let isLoading = false;
    let lastRequestAt = 0;
    let throttleTimerId = null;

    const observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                queueLoad();
            }
        },
        { rootMargin: '200px 0px' }
    );

    updateSentinel();

    if (nextPageUrl !== '') {
        observer.observe(sentinel);
    }

    function queueLoad() {
        if (nextPageUrl === '' || isLoading) {
            return;
        }

        const elapsedMs = Date.now() - lastRequestAt;
        const delayMs = Math.max(0, throttleMs - elapsedMs);

        if (delayMs > 0) {
            if (throttleTimerId !== null) {
                window.clearTimeout(throttleTimerId);
            }

            throttleTimerId = window.setTimeout(() => {
                throttleTimerId = null;
                void loadNextPage();
            }, delayMs);

            return;
        }

        void loadNextPage();
    }

    async function loadNextPage() {
        if (nextPageUrl === '' || isLoading) {
            return;
        }

        isLoading = true;
        lastRequestAt = Date.now();
        setStatus('Loading more repositories...');
        hideError();

        try {
            const response = await fetch(buildFragmentUrl(nextPageUrl, fragmentParam), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const fragment = parseFragment(await response.text());

            if (fragment === null) {
                throw new Error('Missing fragment payload.');
            }

            appendRows(fragment);
            replacePagination(fragment);
            setStatus(nextPageUrl === '' ? 'All repositories loaded.' : 'Loaded more repositories.');
        } catch {
            setStatus('');
            showError('Automatic loading paused. Please use the pagination controls below to continue browsing.');
            observer.disconnect();
        } finally {
            isLoading = false;
            updateSentinel();
        }
    }

    function appendRows(fragment) {
        fragment.querySelectorAll(ROW_SELECTOR).forEach((row) => {
            const repositoryId = row.getAttribute('data-repository-id');

            if (repositoryId === null || seenRepositoryIds.has(repositoryId)) {
                return;
            }

            seenRepositoryIds.add(repositoryId);
            rowsContainer.appendChild(row.cloneNode(true));
        });
    }

    function replacePagination(fragment) {
        const nextPagination = fragment.querySelector(PAGINATION_SELECTOR);

        if (!(nextPagination instanceof HTMLElement)) {
            nextPageUrl = '';
            pagination.dataset.nextPageUrl = '';

            return;
        }

        pagination.innerHTML = nextPagination.innerHTML;
        pagination.dataset.nextPageUrl = nextPagination.dataset.nextPageUrl ?? '';
        nextPageUrl = pagination.dataset.nextPageUrl ?? '';
    }

    function updateSentinel() {
        sentinel.hidden = nextPageUrl === '';

        if (nextPageUrl === '') {
            observer.disconnect();
        }
    }

    function setStatus(message) {
        if (status instanceof HTMLElement) {
            status.textContent = message;
        }
    }

    function showError(message) {
        if (!(error instanceof HTMLElement)) {
            return;
        }

        error.textContent = message;
        error.classList.remove('d-none');
    }

    function hideError() {
        if (!(error instanceof HTMLElement)) {
            return;
        }

        error.textContent = '';
        error.classList.add('d-none');
    }
}

function buildFragmentUrl(url, fragmentParam) {
    const fragmentUrl = new URL(url, window.location.origin);
    fragmentUrl.searchParams.set(fragmentParam, '1');

    return fragmentUrl.toString();
}

function parseFragment(html) {
    const parser = new DOMParser();
    const documentObject = parser.parseFromString(html, 'text/html');

    return documentObject.querySelector(FRAGMENT_SELECTOR);
}
