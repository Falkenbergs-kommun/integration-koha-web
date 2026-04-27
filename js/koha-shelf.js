/**
 * Koha Shelf Display Widget
 *
 * Displays books from a Koha shelf or latest books as UIkit 3 cards
 * Designed for Yootheme Pro in Joomla
 *
 * Usage:
 * <div class="koha-shelf" data-shelf-id="123"></div>
 * <div class="koha-shelf" data-source="latest"></div>
 * <div class="koha-shelf" data-source="latest" data-item-types="BARNBOK,BARNDVD"></div>
 * <div class="koha-shelf" data-source="latest" data-display-mode="slider" data-columns="4"></div>
 *
 * Options (data attributes):
 * - data-shelf-id: Required for shelf source. The shelf ID to load
 * - data-source: Optional. Source type: "shelf" (default) or "latest"
 * - data-item-types: Optional. Filter latest books by item types (comma-separated, e.g., "BARNBOK,BARNDVD")
 * - data-display-mode: Optional. Display mode: "grid" (default) or "slider"
 * - data-show-abstract: Optional. Show book abstracts (true/false). Default: true
 * - data-api-url: Optional. Base URL for API (default: auto-detect)
 * - data-columns: Optional. Number of columns in grid or visible items in slider (2,3,4,5,6). Default: 3
 * - data-card-size: Optional. Card size (small, default, large). Default: default
 * - data-max-books: Optional. Max number of books to display (randomly selected if more available)
 *
 * @version 1.4.1
 * @license MIT
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        defaultColumns: 3,
        defaultCardSize: 'default',
        // Simple 1x1 transparent PNG as placeholder (base64 encoded)
        placeholderImage: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    };

    /**
     * Initialize all shelf widgets on the page
     */
    function initShelves() {
        const shelves = document.querySelectorAll('.koha-shelf:not(.koha-shelf-initialized)');

        shelves.forEach(function(shelfElement) {
            shelfElement.classList.add('koha-shelf-initialized');
            const source = shelfElement.getAttribute('data-source') || 'shelf';
            const shelfId = shelfElement.getAttribute('data-shelf-id');

            // Validate parameters based on source
            if (source === 'shelf' && !shelfId) {
                showError(shelfElement, 'Inget shelf-id angivet. Använd data-shelf-id="123"');
                return;
            }

            loadShelf(shelfElement, source, shelfId);
        });
    }

    /**
     * Load shelf data from API
     */
    function loadShelf(container, source, shelfId) {
        // Show loading state
        showLoading(container);

        // Get API URL
        const apiUrl = getApiUrl(container);

        // Build URL based on source
        let url;
        if (source === 'latest') {
            url = `${apiUrl}/latest.php`;

            // Bygg query-string iterativt så bara satta filter inkluderas.
            // Saknade attribut ger samma URL som tidigare versioner (bakåtkompat).
            const params = new URLSearchParams();

            const itemTypes = container.getAttribute('data-item-types');
            if (itemTypes) params.append('item_type_id', itemTypes);

            const location = container.getAttribute('data-location');
            if (location) params.append('location', location);

            const ccode = container.getAttribute('data-ccode');
            if (ccode) params.append('ccode', ccode);

            const qs = params.toString();
            if (qs) url += '?' + qs;
        } else {
            url = `${apiUrl}/shelf.php?shelfnumber=${encodeURIComponent(shelfId)}`;
        }

        // Fetch data asynchronously
        fetch(url)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (data.status === 'error') {
                    throw new Error(data.message || 'Okänt fel');
                }

                renderShelf(container, data);
            })
            .catch(function(error) {
                showError(container, 'Kunde inte ladda böckerna: ' + error.message);
            });
    }

    /**
     * Get API URL from data attribute or auto-detect
     */
    function getApiUrl(container) {
        const apiUrl = container.getAttribute('data-api-url');
        if (apiUrl) {
            return apiUrl;
        }

        // Auto-detect based on current script location
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src;
            if (src && src.indexOf('koha-shelf.js') !== -1) {
                // Extract base URL (remove /js/koha-shelf.js)
                return src.substring(0, src.lastIndexOf('/js/'));
            }
        }

        // Fallback: assume API is at same domain
        return window.location.origin + '/fbg_apps/services/koha';
    }

    /**
     * Show loading spinner
     */
    function showLoading(container) {
        container.innerHTML = `
            <div class="uk-text-center uk-padding">
                <div uk-spinner="ratio: 2"></div>
                <p class="uk-margin-small-top uk-text-muted">Laddar bokhylla...</p>
            </div>
        `;
    }

    /**
     * Show error message
     */
    function showError(container, message) {
        container.innerHTML = `
            <div class="uk-alert-warning" uk-alert>
                <a class="uk-alert-close" uk-close></a>
                <p><span uk-icon="icon: warning"></span> ${escapeHtml(message)}</p>
            </div>
        `;
    }

    /**
     * Shuffle array (Fisher-Yates algorithm)
     */
    function shuffleArray(array) {
        const shuffled = array.slice(); // Create a copy
        for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }
        return shuffled;
    }

    /**
     * Render shelf with books
     */
    function renderShelf(container, data) {
        const columns = parseInt(container.getAttribute('data-columns')) || CONFIG.defaultColumns;
        const cardSize = container.getAttribute('data-card-size') || CONFIG.defaultCardSize;
        const maxBooks = parseInt(container.getAttribute('data-max-books')) || null;
        const displayMode = container.getAttribute('data-display-mode') || 'grid';
        const showAbstract = container.getAttribute('data-show-abstract') !== 'false';

        // Get books array - support both 'items' (shelf.php) and 'books' format
        let books = data.items || data.books || [];

        // Check if we have books
        if (books.length === 0) {
            container.innerHTML = `
                <div class="uk-alert-primary" uk-alert>
                    <p>Inga böcker finns att visa.</p>
                </div>
            `;
            return;
        }

        // Limit and randomize books if max-books is set
        if (maxBooks && books.length > maxBooks) {
            books = shuffleArray(books).slice(0, maxBooks);
        }

        let html;

        if (displayMode === 'slider') {
            // Slider mode with navigation and height matching for equal card heights
            html = `
                <div class="uk-position-relative uk-visible-toggle" tabindex="-1" uk-slider>
                    <div class="uk-slider-items uk-child-width-1-${columns}@m uk-child-width-1-2@s uk-grid" uk-height-match="target: > div > a > .uk-card">
            `;

            books.forEach(function(book) {
                html += renderBookCard(book, cardSize, showAbstract);
            });

            html += `
                    </div>
                    <a class="uk-position-center-left uk-position-small uk-hidden-hover" href uk-slidenav-previous uk-slider-item="previous" style="background: white; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"></a>
                    <a class="uk-position-center-right uk-position-small uk-hidden-hover" href uk-slidenav-next uk-slider-item="next" style="background: white; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15);"></a>
                </div>
            `;
        } else {
            // Grid mode with height matching for equal card heights
            html = `<div class="uk-child-width-1-${columns}@m uk-child-width-1-2@s" uk-grid uk-height-match="target: > div > a > .uk-card">`;

            books.forEach(function(book) {
                html += renderBookCard(book, cardSize, showAbstract);
            });

            html += '</div>';
        }

        container.innerHTML = html;

        // Reinitialize UIkit components
        if (window.UIkit) {
            UIkit.update(container);
        }
    }

    /**
     * Render a single book card
     */
    function renderBookCard(book, cardSize, showAbstract) {
        // Only use cached images that have been verified by the server
        // Don't link directly to Syndetics as images may not exist
        const imageUrl = book.image_cached_url || CONFIG.placeholderImage;
        // Use api_title (cleaner) if available, fallback to title
        const title = book.api_title || book.title || 'Okänd titel';
        // Use api_author if available, fallback to author
        const author = book.api_author || book.author || '';
        // Use link for catalog link
        const catalogLink = book.link || book.catalog_link || '#';
        const abstract = book.abstract || '';

        // Card size class
        let cardSizeClass = '';
        if (cardSize === 'small') {
            cardSizeClass = ' uk-card-small';
        } else if (cardSize === 'large') {
            cardSizeClass = ' uk-card-large';
        }

        return `
            <div style="height: 100%;">
                <a href="${escapeHtml(catalogLink)}" target="_blank" rel="noopener" class="uk-link-reset" style="display: flex; height: 100%; text-decoration: none; color: inherit;">
                    <div class="uk-card uk-card-default uk-card-hover${cardSizeClass}" style="cursor: pointer; width: 100%; display: flex; flex-direction: column;">
                        <div class="uk-card-media-top" style="height: 400px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #f5f5f5; flex-shrink: 0;">
                            <img src="${imageUrl}"
                                 alt="${escapeHtml(title)}"
                                 loading="lazy"
                                 style="width: 100%; height: 100%; object-fit: cover; object-position: center;">
                        </div>
                        <div class="uk-card-body" style="flex-grow: 1;">
                            <h3 class="uk-card-title uk-margin-remove-bottom">
                                ${escapeHtml(title)}
                            </h3>
                            ${author ? `<p class="uk-text-meta uk-margin-remove-top">${escapeHtml(author)}</p>` : ''}
                            ${showAbstract && abstract ? `<p class="uk-text-small uk-margin-small-top">${truncate(escapeHtml(abstract), 150)}</p>` : ''}
                        </div>
                    </div>
                </a>
            </div>
        `;
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Truncate text to max length
     */
    function truncate(text, maxLength) {
        if (!text || text.length <= maxLength) {
            return text;
        }
        return text.substring(0, maxLength) + '...';
    }

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShelves);
    } else {
        // DOM already loaded
        initShelves();
    }

    // Re-initialize when new content is added (for AJAX-loaded content)
    if (window.MutationObserver) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    // Check if any added nodes contain .koha-shelf
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            if (node.classList && node.classList.contains('koha-shelf')) {
                                initShelves();
                            } else if (node.querySelectorAll) {
                                const shelves = node.querySelectorAll('.koha-shelf');
                                if (shelves.length > 0) {
                                    initShelves();
                                }
                            }
                        }
                    });
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Expose initialization function globally for manual initialization
    window.KohaShelf = {
        init: initShelves,
        version: '1.4.1'
    };

})();
