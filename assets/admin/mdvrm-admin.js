(function () {
    'use strict';

    const cfg = window.MDVRMAdminSettings || {};

    // 1. Settings Page Handler
    const testEmailBtn = document.getElementById('mdvrm-send-test-email');
    if (testEmailBtn) {
        testEmailBtn.addEventListener('click', () => {
            if (!confirm('Send a test report now? This will use the email saved in settings.')) return;
            
            testEmailBtn.disabled = true;
            testEmailBtn.textContent = 'Sending...';

            const u = new URL(cfg.sendReportUrl);
            window.fetch(u.toString(), {
                method: 'POST',
                headers: { 'X-WP-Nonce': cfg.nonce }
            }).then(r => r.json()).then(res => {
                if(res.status === 'sent') { alert('Email sent successfully!'); } 
                else { alert('Error: ' + (res.message || 'Unknown')); }
            }).catch(e => {
                alert('Network Error');
            }).finally(() => { 
                testEmailBtn.disabled = false; 
                testEmailBtn.textContent = 'Test Email';
            });
        });
    }

    // 2. Live Log Page Handler
    const root = document.getElementById('mdvrm-live-log');
    if (!root || !cfg.restUrl) {
        return;
    }

    const state = {
        page: 1,
        perPage: 20,
        order: 'desc',
        orderBy: 'event_time',
        session: '',
        url: '',
        device: '',
        net: '',
        total: 0,
    };

    const toolbar = document.createElement('div');
    toolbar.className = 'tablenav top';
    root.appendChild(toolbar);
    
    // Fix for the gap above header: Standard WP tablenav has clear floats but we used flex in CSS. 
    // We will ensure clear:both is applied to reset layout flow.
    toolbar.style.clear = 'both';

    const controls = document.createElement('div');
    controls.className = 'alignleft actions';
    controls.style.display = 'flex';
    controls.style.gap = '6px';
    controls.style.flexWrap = 'wrap';
    controls.style.alignItems = 'center';
    toolbar.appendChild(controls);

    // Filters
    const sessionInput = createInput('text', 'Session ID', '120px');
    const urlInput = createInput('text', 'URL path', '120px');
    
    // Device Select
    const deviceSelect = document.createElement('select');
    ['', 'mobile', 'tablet', 'desktop'].forEach(d => {
        const o = document.createElement('option');
        o.value = d;
        o.textContent = d ? d.charAt(0).toUpperCase() + d.slice(1) : 'All Devices';
        deviceSelect.appendChild(o);
    });
    controls.appendChild(deviceSelect);

    // Net Select
    const netSelect = document.createElement('select');
    ['', '4g', '3g', '2g', 'slow-2g'].forEach(n => {
        const o = document.createElement('option');
        o.value = n;
        o.textContent = n ? n.toUpperCase() : 'All Net';
        netSelect.appendChild(o);
    });
    controls.appendChild(netSelect);

    const applyBtn = document.createElement('button');
    applyBtn.className = 'button button-secondary';
    applyBtn.textContent = 'Filter';
    controls.appendChild(applyBtn);

    const clearBtn = document.createElement('button');
    clearBtn.className = 'button button-secondary';
    clearBtn.textContent = 'Clear';
    controls.appendChild(clearBtn);

    const reportBtn = document.createElement('button');
    reportBtn.className = 'button button-secondary';
    reportBtn.style.marginLeft = '10px';
    reportBtn.innerHTML = '<span class="dashicons dashicons-analytics" style="line-height:26px; margin-right:4px;"></span> Generate Report';
    controls.appendChild(reportBtn);

    function createInput(type, placeholder, width) {
        const i = document.createElement('input');
        i.type = type;
        i.placeholder = placeholder;
        i.className = 'regular-text';
        i.style.width = width;
        controls.appendChild(i);
        return i;
    }


    const perPageSelect = document.createElement('select');
    perPageSelect.className = 'mdvrm-per-page';
    [20, 50, 100, 200].forEach(num => {
        const opt = document.createElement('option');
        opt.value = num;
        opt.textContent = num + ' / page';
        if (num === state.perPage) opt.selected = true;
        perPageSelect.appendChild(opt);
    });
    perPageSelect.style.marginRight = '8px';
    perPageSelect.style.verticalAlign = 'middle';
    controls.appendChild(perPageSelect);

    const refreshBtn = document.createElement('button');
    refreshBtn.className = 'button button-secondary';
    refreshBtn.innerHTML = '<span class="dashicons dashicons-update" style="line-height: 28px;"></span>';
    controls.appendChild(refreshBtn);

    const paginationBox = document.createElement('div');
    paginationBox.className = 'tablenav-pages';
    toolbar.appendChild(paginationBox);

    const tableWrap = document.createElement('div');
    tableWrap.className = 'mdvrm-table-wrap';
    root.appendChild(tableWrap);

    // Bottom pagination
    const toolbarBottom = document.createElement('div');
    toolbarBottom.className = 'tablenav bottom';
    root.appendChild(toolbarBottom);
    
    const paginationBoxBottom = document.createElement('div');
    paginationBoxBottom.className = 'tablenav-pages';
    toolbarBottom.appendChild(paginationBoxBottom);

    // Initial load: check for preload
    const preload = root.getAttribute('data-preload');
    if (preload) {
        try {
            const json = JSON.parse(preload);
            if (json && json.data) {
                state.total = json.total || 0;
                renderTable(json.data || []);
                renderPaginationInBox(paginationBox);
                renderPaginationInBox(paginationBoxBottom);
            } else {
                fetchLogs();
            }
        } catch(e) { fetchLogs(); }
    } else {
        fetchLogs();
    }

    applyBtn.addEventListener('click', () => {
        state.page = 1;
        state.session = sessionInput.value.trim();
        state.url = urlInput.value.trim();
        state.device = deviceSelect.value;
        state.net = netSelect.value;
        fetchLogs();
    });

    clearBtn.addEventListener('click', () => {
        sessionInput.value = '';
        urlInput.value = '';
        deviceSelect.value = '';
        netSelect.value = '';
        
        state.session = '';
        state.url = '';
        state.device = '';
        state.net = '';
        state.page = 1;
        fetchLogs();
    });

    reportBtn.addEventListener('click', () => {
        openReport();
    });

    perPageSelect.addEventListener('change', (e) => {
        state.perPage = parseInt(e.target.value);
        state.page = 1;
        fetchLogs();
    });

    refreshBtn.addEventListener('click', () => {
        fetchLogs();
    });

    function openReport() {
        const url = new URL(cfg.statsUrl);

        reportBtn.disabled = true;
        reportBtn.textContent = 'Loading...';

        // Use current filters
        if (state.session) url.searchParams.set('session_id', state.session);
        if (state.url) url.searchParams.set('url', state.url);
        if (state.device) url.searchParams.set('device', state.device);
        if (state.net) url.searchParams.set('net', state.net);

        window.fetch(url.toString(), {
             headers: { 'X-WP-Nonce': cfg.nonce }
        })
        .then(r => r.json())
        .then(stats => {
             renderModal(stats);
        })
        .finally(() => {
             reportBtn.disabled = false;
             reportBtn.innerHTML = '<span class="dashicons dashicons-analytics" style="line-height:26px; margin-right:4px;"></span> Generate Report';
        });
    }

    function renderModal(stats) {
        const overlay = document.createElement('div');
        overlay.className = 'mdvrm-modal-overlay';
        
        const modal = document.createElement('div');
        modal.className = 'mdvrm-modal';
        // Increase max height to separate scroll
        modal.style.maxHeight = '90vh';
        modal.style.overflowY = 'auto';

        const close = document.createElement('button');
        close.className = 'mdvrm-modal-close';
        close.innerHTML = '&times;';
        close.onclick = () => document.body.removeChild(overlay);
        modal.appendChild(close);

        const header = document.createElement('div');
        header.style.marginBottom = '20px';
        header.style.paddingRight = '30px'; // Space for close button
        
        const title = document.createElement('h2');
        title.textContent = 'Performance Report';
        title.style.margin = '0';
        header.appendChild(title);
        
        modal.appendChild(header);
        
        const sub = document.createElement('p');
        sub.textContent = 'Based on ' + stats.count + ' collected pageviews matching current filters.';
        modal.appendChild(sub);

        const grid = document.createElement('div');
        grid.className = 'mdvrm-report-grid';

        const metrics = [
            { label: 'Avg TTFB', val: stats.avg_ttfb + 's' },
            { label: 'Avg LCP', val: stats.avg_lcp + 's' },
            { label: 'Avg Server Gen', val: stats.avg_server + 's' },
            { label: 'Avg Total Load', val: stats.avg_load + 's' },
            { label: 'P75 LCP', val: stats.p75_lcp + 's' },
            { label: 'Total Views', val: stats.count },
        ];

        metrics.forEach(m => {
            const box = document.createElement('div');
            box.className = 'mdvrm-stat-box';
            const valDiv = document.createElement('div');
            valDiv.className = 'mdvrm-stat-value';
            valDiv.textContent = m.val;
            box.appendChild(valDiv);
            const labelDiv = document.createElement('div');
            labelDiv.className = 'mdvrm-stat-label';
            labelDiv.textContent = m.label;
            box.appendChild(labelDiv);
            grid.appendChild(box);
        });
        modal.appendChild(grid);

        // Slowest Pages Section
        if (stats.slowest_lcp && stats.slowest_lcp.length > 0) {
            const h3 = document.createElement('h3');
            h3.textContent = 'Top Slowest Pages (by LCP)';
            h3.style.marginTop = '25px';
            h3.style.borderBottom = '1px solid #ddd';
            h3.style.paddingBottom = '10px';
            modal.appendChild(h3);

            const tbl = document.createElement('table');
            tbl.className = 'wp-list-table widefat striped';
            const thead = document.createElement('thead');
            const hdrRow = document.createElement('tr');
            ['URL', 'Avg LCP', 'Hits'].forEach(function (text) {
                const th = document.createElement('th');
                th.textContent = text;
                hdrRow.appendChild(th);
            });
            thead.appendChild(hdrRow);
            tbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            stats.slowest_lcp.forEach(function (r) {
                const row = document.createElement('tr');
                const urlTd = document.createElement('td');
                const link = document.createElement('a');
                link.href = r.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = cutUrl(r.url);
                urlTd.appendChild(link);
                row.appendChild(urlTd);

                const lcpTd = document.createElement('td');
                lcpTd.className = 'mdvrm-metric-poor';
                lcpTd.textContent = Number(r.avg_lcp).toFixed(3) + 's';
                row.appendChild(lcpTd);

                const hitsTd = document.createElement('td');
                hitsTd.textContent = r.count;
                row.appendChild(hitsTd);

                tbody.appendChild(row);
            });
            tbl.appendChild(tbody);
            modal.appendChild(tbl);
        }

        // Actions Footer
        const footer = document.createElement('div');
        footer.style.marginTop = '25px';
        footer.style.paddingTop = '15px';
        footer.style.borderTop = '1px solid #ddd';
        footer.style.display = 'flex';
        footer.style.justifyContent = 'flex-end';
        
        // Email Button
        const emailBtn = document.createElement('button');
        emailBtn.className = 'button button-secondary';
        emailBtn.innerHTML = '<span class="dashicons dashicons-email-alt" style="line-height:26px;margin-right:4px;"></span> Send Report to Email';
        emailBtn.onclick = () => {
             emailBtn.disabled = true;
             emailBtn.textContent = 'Sending...';
             const u = new URL(cfg.sendReportUrl);
             window.fetch(u.toString(), {
                 method: 'POST',
                 headers: { 'X-WP-Nonce': cfg.nonce }
             }).then(r => r.json()).then(res => {
                 if(res.status === 'sent') { alert('Email sent successfully!'); } 
                 else { alert('Error: ' + (res.message || 'Unknown')); }
             }).finally(() => { 
                 emailBtn.disabled = false; 
                 emailBtn.innerHTML = '<span class="dashicons dashicons-email-alt" style="line-height:26px;margin-right:4px;"></span> Send Report to Email';
             });
        };
        footer.appendChild(emailBtn);
        modal.appendChild(footer);

        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) document.body.removeChild(overlay);
        });
    }

    function fetchLogs() {
        const url = new URL(cfg.restUrl);
        url.searchParams.set('page', state.page);
        url.searchParams.set('per_page', state.perPage);
        url.searchParams.set('order', state.order);
        url.searchParams.set('order_by', state.orderBy);
        if (state.session) url.searchParams.set('session_id', state.session);
        if (state.url) url.searchParams.set('url', state.url);
        if (state.device) url.searchParams.set('device', state.device);
        if (state.net) url.searchParams.set('net', state.net);
        
        // Disable buttons
        applyBtn.disabled = true;
        refreshBtn.classList.add('updating-message');

        window.fetch(url.toString(), {
            headers: {
                'X-MDVRM-Nonce': '1', 
                'X-WP-Nonce': cfg.nonce
            },
        })
            .then((res) => res.json())
            .then((json) => {
                state.total = json.total || 0;
                renderTable(json.data || []);
                renderPaginationInBox(paginationBox);
                renderPaginationInBox(paginationBoxBottom);
            })
            .catch((err) => {
                console.error('MDVRM fetch error', err);
            })
            .finally(() => {
                applyBtn.disabled = false;
                refreshBtn.classList.remove('updating-message');
            });
    }

    function renderPaginationInBox(box) {
        box.innerHTML = '';
        const totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
        
        const countSpan = document.createElement('span');
        countSpan.className = 'displaying-num';
        countSpan.textContent = state.total + ' items';
        box.appendChild(countSpan);

        const links = document.createElement('span');
        links.className = 'pagination-links';
        box.appendChild(links);

        // Prev
        const prevBtn = document.createElement('a');
        prevBtn.className = 'first-page button' + (state.page <= 1 ? ' disabled' : '');
        prevBtn.innerHTML = '<span class="screen-reader-text">First page</span><span aria-hidden="true">«</span>';
        if (state.page > 1) prevBtn.onclick = () => goToPage(1);
        links.appendChild(prevBtn);

        const prevPageBtn = document.createElement('a');
        prevPageBtn.className = 'prev-page button' + (state.page <= 1 ? ' disabled' : '');
        prevPageBtn.innerHTML = '<span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span>';
        if (state.page > 1) prevPageBtn.onclick = () => goToPage(state.page - 1);
        links.appendChild(prevPageBtn);

        const current = document.createElement('span');
        current.className = 'paging-input';
        const currentSpan = document.createElement('span');
        currentSpan.className = 'current-page';
        currentSpan.textContent = state.page;
        current.appendChild(currentSpan);
        current.appendChild(document.createTextNode(' of '));
        const totalSpan = document.createElement('span');
        totalSpan.className = 'total-pages';
        totalSpan.textContent = totalPages;
        current.appendChild(totalSpan);
        links.appendChild(current);

        // Next
        const nextPageBtn = document.createElement('a');
        nextPageBtn.className = 'next-page button' + (state.page >= totalPages ? ' disabled' : '');
        nextPageBtn.innerHTML = '<span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span>';
        if (state.page < totalPages) nextPageBtn.onclick = () => goToPage(state.page + 1);
        links.appendChild(nextPageBtn);
        
        const lastBtn = document.createElement('a');
        lastBtn.className = 'last-page button' + (state.page >= totalPages ? ' disabled' : '');
        lastBtn.innerHTML = '<span class="screen-reader-text">Last page</span><span aria-hidden="true">»</span>';
        if (state.page < totalPages) lastBtn.onclick = () => goToPage(totalPages);
        links.appendChild(lastBtn);
    }

    function goToPage(n) {
        state.page = n;
        fetchLogs();
    }

    function renderTable(rows) {
        tableWrap.innerHTML = '';
        if (!rows.length) {
            const empty = document.createElement('div');
            empty.className = 'notice notice-warning inline';
            const emptyP = document.createElement('p');
            emptyP.textContent = cfg.i18n ? cfg.i18n.empty : 'No entries yet.';
            empty.appendChild(emptyP);
            tableWrap.appendChild(empty);
            return;
        }

        const table = document.createElement('table');
        table.className = 'wp-list-table widefat fixed striped table-view-list';
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        const columns = [
            { key: 'event_time', label: cfg.i18n ? cfg.i18n.timestamp : 'Time', width: '14%' },
            { key: 'url', label: cfg.i18n ? cfg.i18n.url : 'URL', width: '22%' },
            { key: 'server_time', label: 'Server Gen', width: '8%', sortable: true },
            { key: 'ttfb', label: cfg.i18n ? cfg.i18n.ttfb : 'TTFB', width: '7%', sortable: true },
            { key: 'lcp', label: cfg.i18n ? cfg.i18n.lcp : 'LCP', width: '7%', sortable: true },
            { key: 'total_load', label: cfg.i18n ? cfg.i18n.load : 'Load', width: '7%', sortable: true },
            { key: 'device', label: cfg.i18n ? cfg.i18n.device : 'Dev', width: '5%' },
            { key: 'net', label: cfg.i18n ? cfg.i18n.net : 'Net', width: '5%' },
            { key: 'session_id', label: 'Session', width: 'auto' },
        ];

        columns.forEach((col) => {
            const th = document.createElement('th');
            th.scope = 'col';
            if (col.width) th.style.width = col.width;
            
            if (col.sortable || col.key === 'event_time') {
                const link = document.createElement('a');
                link.href = '#';
                const labelSpan = document.createElement('span');
                labelSpan.textContent = col.label;
                link.appendChild(labelSpan);
                const indicatorSpan = document.createElement('span');
                indicatorSpan.className = 'sorting-indicator';
                link.appendChild(indicatorSpan);
                if (state.orderBy === col.key) {
                    th.className = 'sorted ' + state.order;
                } else {
                    th.className = 'sortable ' + 'desc';
                }
                link.onclick = (e) => {
                    e.preventDefault();
                    toggleSort(col.key);
                }
                th.appendChild(link);
            } else {
                th.textContent = col.label;
            }
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach((row) => {
            const tr = document.createElement('tr');

            appendCell(tr, row.event_time);
            appendUrlCell(tr, row.url);
            appendCell(tr, formatNumber(row.server_time) + 's');
            appendMetricCell(tr, row.ttfb, 'ttfb');
            appendMetricCell(tr, row.lcp, 'lcp');
            appendCell(tr, formatNumber(row.total_load));
            appendCell(tr, row.device);
            appendCell(tr, row.net);
            appendSessionCell(tr, row.session_id);

            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        tableWrap.appendChild(table);
    }
    
    function cutUrl(url) {
        try {
            const u = new URL(url);
            return u.pathname + u.search;
        } catch(e) { return url; }
    }

    function appendCell(tr, content) {
        const td = document.createElement('td');
        td.textContent = content == null ? '' : content;
        tr.appendChild(td);
    }

    function appendMetricCell(tr, value, key) {
        const td = document.createElement('td');
        const span = document.createElement('span');
        span.className = metricClass(Number(value || 0), key);
        span.textContent = formatNumber(value) + 's';
        td.appendChild(span);
        tr.appendChild(td);
    }

    function appendUrlCell(tr, url) {
        const td = document.createElement('td');
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = cutUrl(url);
        td.appendChild(a);
        tr.appendChild(td);
    }

    function appendSessionCell(tr, session) {
        const td = document.createElement('td');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button-link';
        btn.textContent = session || '';
        btn.addEventListener('click', () => {
            sessionInput.value = session;
            state.session = session;
            state.page = 1;
            fetchLogs();
        });
        td.appendChild(btn);
        tr.appendChild(td);
    }

    function formatMetric(value, key) {
        return formatNumber(value) + 's';
    }

    function formatNumber(num) {
        const n = Number(num || 0);
        // If small number, show more precision
        if (n > 0 && n < 0.01) {
            return n.toFixed(4);
        }
        return n.toFixed(3); // Increased precision for "honest" values
    }

    function metricClass(value, key) {
        // Hardcoded thresholds for now if cfg is missing
        const thresholds = (cfg.thresholds && cfg.thresholds[key]) ? cfg.thresholds[key] : null;
        let good = 0.8, poor = 2.5;

        if (key === 'lcp') { good = 2.5; poor = 4.0; }
        if (key === 'ttfb') { good = 0.8; poor = 1.8; }
        
        if (thresholds) {
            good = thresholds.good;
            poor = thresholds.poor || thresholds.meh;
        }

        if (value <= good) return 'mdvrm-metric-good';
        if (value <= poor) return 'mdvrm-metric-needs-improvement';
        return 'mdvrm-metric-poor';
    }

    function toggleSort(key) {
        if (state.orderBy === key) {
            state.order = state.order === 'asc' ? 'desc' : 'asc';
        } else {
            state.orderBy = key;
            state.order = 'desc';
        }
        fetchLogs();
    }
})();
