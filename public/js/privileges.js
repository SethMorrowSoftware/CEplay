/**
 * Privileges & Time Play Groups page.
 */
(function() {
    App.registerRoute('#/privileges', { render: renderPrivileges });

    async function renderPrivileges(container) {
        container.appendChild(App.el('div', { className: 'page-header' }, [
            App.el('h1', { className: 'page-title', textContent: 'Privileges & Time Plays' }),
            App.el('p', { className: 'text-muted', textContent: 'View privilege groups and time play groups from CenterEdge.' })
        ]));

        var content = App.el('div', { id: 'priv-content' });
        content.appendChild(App.loading());
        container.appendChild(content);

        await loadData();
    }

    async function loadData() {
        var content = document.getElementById('priv-content');
        if (!content) return;

        try {
            var [privData, tpData] = await Promise.all([
                API.get('privileges').catch(function(e) { return { privilegeGroups: [], error: e.message }; }),
                API.get('timeplays').catch(function(e) { return { timePlayGroups: [], error: e.message }; })
            ]);

            content.innerHTML = '';

            // Privilege Groups
            content.appendChild(buildPrivilegeSection(privData));

            // Time Play Groups
            content.appendChild(buildTimePlaySection(tpData));

        } catch (err) {
            content.innerHTML = '';
            App.toast(err.message, 'error');
        }
    }

    function buildPrivilegeSection(data) {
        var card = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        card.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Privilege Groups' }),
            App.el('span', { className: 'badge', textContent: (data.totalCount || 0) + ' total' })
        ]));

        var body = App.el('div', { className: 'card-body' });
        var groups = data.privilegeGroups || [];

        if (data.error) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'Error loading: ' + data.error }));
        } else if (groups.length === 0) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No privilege groups found. The card system may not support privileges.' }));
        } else {
            var table = App.el('table', { className: 'table' });
            var thead = App.el('thead');
            thead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'ID' }),
                App.el('th', { textContent: 'Name' })
            ]));
            table.appendChild(thead);

            var tbody = App.el('tbody');
            groups.forEach(function(g) {
                tbody.appendChild(App.el('tr', {}, [
                    App.el('td', {}, [App.el('code', { textContent: String(g.id) })]),
                    App.el('td', { textContent: g.name || '-' })
                ]));
            });
            table.appendChild(tbody);

            var tw = App.el('div', { className: 'table-responsive' });
            tw.appendChild(table);
            body.appendChild(tw);
        }

        card.appendChild(body);
        return card;
    }

    function buildTimePlaySection(data) {
        var card = App.el('div', { className: 'card', style: { marginBottom: '1.5rem' } });
        card.appendChild(App.el('div', { className: 'card-header' }, [
            App.el('h3', { className: 'card-title', textContent: 'Time Play Groups' }),
            App.el('span', { className: 'badge', textContent: (data.totalCount || 0) + ' total' })
        ]));

        var body = App.el('div', { className: 'card-body' });
        var groups = data.timePlayGroups || [];

        if (data.error) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'Error loading: ' + data.error }));
        } else if (groups.length === 0) {
            body.appendChild(App.el('p', { className: 'text-muted', textContent: 'No time play groups found. The card system may not support time plays.' }));
        } else {
            var table = App.el('table', { className: 'table' });
            var thead = App.el('thead');
            thead.appendChild(App.el('tr', {}, [
                App.el('th', { textContent: 'ID' }),
                App.el('th', { textContent: 'Name' })
            ]));
            table.appendChild(thead);

            var tbody = App.el('tbody');
            groups.forEach(function(g) {
                tbody.appendChild(App.el('tr', {}, [
                    App.el('td', {}, [App.el('code', { textContent: String(g.id) })]),
                    App.el('td', { textContent: g.name || '-' })
                ]));
            });
            table.appendChild(tbody);

            var tw = App.el('div', { className: 'table-responsive' });
            tw.appendChild(table);
            body.appendChild(tw);
        }

        card.appendChild(body);
        return card;
    }
})();
