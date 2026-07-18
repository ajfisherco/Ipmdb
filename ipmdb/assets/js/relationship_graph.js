/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/assets/js/relationship_graph.js
|--------------------------------------------------------------------------
| IPMdb Relationship Explorer
| Normalizes both the current nodes/edges payload and the legacy
| assets/relationships payload before rendering with Cytoscape.
|--------------------------------------------------------------------------
*/

(() => {
    'use strict';

    const graphElement = document.getElementById('relationshipGraph');
    const graphDataElement = document.getElementById('relationshipGraphData');
    const selectedBox = document.getElementById('selectedAssetBox');

    if (!graphElement || !graphDataElement) {
        return;
    }

    function showError(message) {
        graphElement.innerHTML =
            '<div class="graph-error">' + escapeHtml(message) + '</div>';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function firstValue(object, keys, fallback = '') {
        if (!object || typeof object !== 'object') {
            return fallback;
        }

        for (const key of keys) {
            const value = object[key];

            if (value !== undefined && value !== null && value !== '') {
                return value;
            }
        }

        return fallback;
    }

    function asArray(value) {
        if (Array.isArray(value)) {
            return value;
        }

        if (value && typeof value === 'object') {
            return Object.values(value);
        }

        return [];
    }

    function unwrapData(item) {
        if (
            item &&
            typeof item === 'object' &&
            item.data &&
            typeof item.data === 'object'
        ) {
            return item.data;
        }

        return item || {};
    }

    function normalKey(value) {
        return String(value ?? '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    const categoryColours = {
        governance: '#2563eb',
        housing: '#16a34a',
        technology: '#7c3aed',
        economics: '#d97706',
        economic_security: '#d97706',
        legal: '#dc2626',
        transportation: '#0891b2',
        tdm: '#0891b2',
        copo: '#4f46e5',
        pcwm: '#9333ea',
        public_service: '#0f766e',
        dad: '#ca8a04',
        uncategorized: '#64748b'
    };

    const relationshipColours = {
        related: '#64748b',
        relates_to: '#64748b',
        implements: '#16a34a',
        depends: '#d97706',
        depends_on: '#d97706',
        parent: '#2563eb',
        child: '#2563eb',
        part_of: '#2563eb',
        duplicate: '#6b7280',
        derived_from: '#7c3aed',
        supports: '#0f766e',
        enhances: '#0f766e',
        documents: '#0891b2',
        extends: '#7c3aed',
        replaces: '#7c3aed',
        supersedes: '#7c3aed',
        blocks: '#dc2626',
        opposes: '#dc2626'
    };

    function categoryColour(category, suppliedColour = '') {
        return suppliedColour ||
            categoryColours[normalKey(category)] ||
            categoryColours.uncategorized;
    }

    function relationshipColour(type, suppliedColour = '') {
        return suppliedColour ||
            relationshipColours[normalKey(type)] ||
            '#94a3b8';
    }

    function assetReference(value) {
        if (value && typeof value === 'object') {
            return String(firstValue(value, [
                'asset_id',
                'assetId',
                'id',
                'code'
            ], '')).trim();
        }

        return String(value ?? '').trim();
    }

    function normalizeAsset(item) {
        const asset = unwrapData(item);
        const assetId = assetReference(firstValue(asset, [
            'asset_id',
            'assetId',
            'id',
            'code',
            'reference',
            'reference_code'
        ], ''));
        const category = String(firstValue(asset, [
            'category',
            'category_name',
            'asset_category',
            'type',
            'asset_type'
        ], 'Uncategorized'));

        return {
            asset_id: assetId,
            title: String(firstValue(asset, [
                'title',
                'asset_title',
                'name',
                'label'
            ], assetId || 'Untitled Asset')),
            category,
            status: String(firstValue(asset, [
                'status',
                'asset_status'
            ], 'Draft')),
            version: String(firstValue(asset, [
                'version',
                'version_number',
                'current_version'
            ], '1.0')).replace(/^v/i, ''),
            idea: String(firstValue(asset, [
                'idea',
                'description',
                'summary',
                'content',
                'body'
            ], '')),
            colour: categoryColour(
                category,
                String(firstValue(asset, ['colour', 'color'], ''))
            )
        };
    }

    function normalizeRelationship(item, index) {
        const relationship = unwrapData(item);
        const source = assetReference(firstValue(relationship, [
            'source_asset_id',
            'sourceAssetId',
            'source_id',
            'source',
            'from_asset_id',
            'from'
        ], ''));
        const target = assetReference(firstValue(relationship, [
            'target_asset_id',
            'targetAssetId',
            'target_id',
            'target',
            'to_asset_id',
            'to'
        ], ''));
        const label = String(firstValue(relationship, [
            'relationship_type',
            'relationshipType',
            'type_label',
            'label',
            'type',
            'relation'
        ], 'Related'));

        return {
            relationship_id: String(firstValue(relationship, [
                'relationship_id',
                'relationshipId',
                'id'
            ], 'rel_' + index)),
            source_asset_id: source,
            target_asset_id: target,
            relationship_type: label,
            colour: relationshipColour(
                label,
                String(firstValue(relationship, ['colour', 'color'], ''))
            )
        };
    }

    function normalizeGraph(rawGraph) {
        let payload = rawGraph;

        if (
            payload &&
            typeof payload === 'object' &&
            payload.data &&
            typeof payload.data === 'object' &&
            !Array.isArray(payload.data)
        ) {
            payload = payload.data;
        }

        payload = payload && typeof payload === 'object' ? payload : {};

        const rawAssets = firstValue(payload, ['assets', 'nodes'], []);
        const rawRelationships = firstValue(
            payload,
            ['relationships', 'edges'],
            []
        );
        const assets = [];
        const assetIds = new Set();

        asArray(rawAssets).forEach(item => {
            const asset = normalizeAsset(item);

            if (!asset.asset_id || assetIds.has(asset.asset_id)) {
                return;
            }

            assetIds.add(asset.asset_id);
            assets.push(asset);
        });

        const relationships = [];
        const relationshipIds = new Set();

        asArray(rawRelationships).forEach((item, index) => {
            const relationship = normalizeRelationship(item, index);

            if (
                !relationship.source_asset_id ||
                !relationship.target_asset_id ||
                !assetIds.has(relationship.source_asset_id) ||
                !assetIds.has(relationship.target_asset_id)
            ) {
                return;
            }

            let relationshipId = relationship.relationship_id;

            if (relationshipIds.has(relationshipId)) {
                relationshipId += '_' + index;
            }

            relationship.relationship_id = relationshipId;
            relationshipIds.add(relationshipId);
            relationships.push(relationship);
        });

        return {
            assets,
            relationships,
            focus_asset_id: String(firstValue(payload, [
                'focus_asset_id',
                'focusAssetId',
                'focus_id',
                'focus'
            ], ''))
        };
    }

    function parseGraphData() {
        try {
            return normalizeGraph(JSON.parse(graphDataElement.textContent));
        } catch (error) {
            console.error('Unable to read relationship graph data.', error);
            showError('Unable to read graph data.');
            return null;
        }
    }

    function buildElements(graph) {
        const elements = [];

        graph.assets.forEach(asset => {
            elements.push({
                group: 'nodes',
                data: {
                    id: asset.asset_id,
                    asset_id: asset.asset_id,
                    title: asset.title,
                    category: asset.category,
                    status: asset.status,
                    version: asset.version,
                    idea: asset.idea,
                    colour: asset.colour
                }
            });
        });

        graph.relationships.forEach(relationship => {
            elements.push({
                group: 'edges',
                data: {
                    id: relationship.relationship_id,
                    source: relationship.source_asset_id,
                    target: relationship.target_asset_id,
                    label: relationship.relationship_type,
                    colour: relationship.colour
                }
            });
        });

        return elements;
    }

    if (typeof window.cytoscape !== 'function') {
        showError('Cytoscape failed to load.');
        return;
    }

    let graph = parseGraphData();

    if (!graph) {
        return;
    }

    if (graph.assets.length === 0) {
        showError('No graph assets match these filters.');
        return;
    }

    const cy = window.cytoscape({
        container: graphElement,
        elements: buildElements(graph),
        minZoom: 0.2,
        maxZoom: 3,
        wheelSensitivity: 0.18,
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': 'data(colour)',
                    'label': 'data(title)',
                    'text-wrap': 'wrap',
                    'text-max-width': 160,
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'font-size': 12,
                    'color': '#ffffff',
                    'width': 46,
                    'height': 46,
                    'border-width': 2,
                    'border-color': '#ffffff'
                }
            },
            {
                selector: 'edge',
                style: {
                    'curve-style': 'bezier',
                    'width': 2,
                    'line-color': 'data(colour)',
                    'target-arrow-color': 'data(colour)',
                    'target-arrow-shape': 'triangle',
                    'label': 'data(label)',
                    'font-size': 9,
                    'text-background-opacity': 1,
                    'text-background-color': '#ffffff',
                    'text-background-padding': 2,
                    'color': '#334155'
                }
            },
            {
                selector: 'node:selected',
                style: {
                    'border-width': 5,
                    'border-color': '#f59e0b'
                }
            }
        ],
        layout: {
            name: 'cose',
            animate: true,
            fit: true,
            padding: 60
        }
    });

    let labelsVisible = true;

    function renderSelected(node) {
        if (!selectedBox || !node || !node.length) {
            return;
        }

        const data = node.data();

        selectedBox.innerHTML = `
            <p class="asset-code">${escapeHtml(data.asset_id)}</p>
            <h3>${escapeHtml(data.title)}</h3>
            <dl>
                <dt>Category</dt>
                <dd>${escapeHtml(data.category)}</dd>
                <dt>Status</dt>
                <dd>${escapeHtml(data.status)}</dd>
                <dt>Version</dt>
                <dd>v${escapeHtml(data.version)}</dd>
            </dl>
            <p>${escapeHtml(data.idea)}</p>
            <div class="detail-actions">
                <a href="/ipmdb/viewer.php?asset_id=${encodeURIComponent(data.asset_id)}">View</a>
                <a href="/ipmdb/edit.php?asset_id=${encodeURIComponent(data.asset_id)}">Edit</a>
                <a href="/ipmdb/relationship_add.php?asset_id=${encodeURIComponent(data.asset_id)}">Relate</a>
            </div>
        `;
    }

    function runLayout() {
        cy.layout({
            name: 'cose',
            animate: true,
            fit: true,
            padding: 60
        }).run();
    }

    function selectFocusAsset() {
        if (!graph.focus_asset_id) {
            return;
        }

        const node = cy.getElementById(graph.focus_asset_id);

        if (!node.length) {
            return;
        }

        cy.$(':selected').unselect();
        node.select();
        renderSelected(node);
        cy.animate({
            center: { eles: node },
            zoom: 1.2,
            duration: 300
        });
    }

    function replaceGraph(nextPayload) {
        const nextGraph = normalizeGraph(nextPayload);

        if (nextGraph.assets.length === 0) {
            throw new Error('The refreshed graph did not contain any assets.');
        }

        graph = nextGraph;

        cy.batch(() => {
            cy.elements().remove();
            cy.add(buildElements(graph));
        });

        runLayout();
        selectFocusAsset();
    }

    cy.on('tap', 'node', event => {
        const node = event.target;

        renderSelected(node);
        cy.animate({
            center: { eles: node },
            duration: 250
        });
    });

    cy.on('tap', 'edge', event => {
        if (!selectedBox) {
            return;
        }

        const edge = event.target;

        selectedBox.innerHTML = `
            <h3>${escapeHtml(edge.data('label'))}</h3>
            <p>
                <strong>${escapeHtml(edge.source().data('title'))}</strong>
                &nbsp;&rarr;&nbsp;
                <strong>${escapeHtml(edge.target().data('title'))}</strong>
            </p>
        `;
    });

    cy.on('mouseover', 'node', event => {
        event.target.animate(
            { style: { width: 54, height: 54 } },
            { duration: 120 }
        );
    });

    cy.on('mouseout', 'node', event => {
        event.target.animate(
            { style: { width: 46, height: 46 } },
            { duration: 120 }
        );
    });

    cy.on('dbltap', 'node', event => {
        const assetId = event.target.data('asset_id');

        if (assetId) {
            window.location.href =
                '/ipmdb/viewer.php?asset_id=' + encodeURIComponent(assetId);
        }
    });

    cy.on('cxttap', 'node', event => {
        const assetId = event.target.data('asset_id');

        if (assetId) {
            window.location.href =
                '/ipmdb/relationship_add.php?asset_id=' +
                encodeURIComponent(assetId);
        }
    });

    document.querySelectorAll('[data-graph-action]').forEach(button => {
        button.addEventListener('click', () => {
            switch (button.dataset.graphAction) {
                case 'fit':
                    cy.fit(undefined, 60);
                    break;

                case 'reset':
                    runLayout();
                    break;

                case 'labels':
                    labelsVisible = !labelsVisible;
                    cy.style()
                        .selector('node')
                        .style('label', labelsVisible ? 'data(title)' : '')
                        .selector('edge')
                        .style('label', labelsVisible ? 'data(label)' : '')
                        .update();
                    break;
            }
        });
    });

    async function refreshGraph() {
        const url = graphElement.dataset.api;

        if (!url) {
            return;
        }

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Unable to refresh graph.');
            }

            replaceGraph(await response.json());
        } catch (error) {
            console.error('Unable to refresh relationship graph.', error);
        }
    }

    cy.ready(selectFocusAsset);

    let resizeFrame = 0;

    window.addEventListener('resize', () => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(() => {
            cy.resize();
            cy.fit(undefined, 60);
        });
    });

    window.ipmdbRelationshipGraph = cy;
    window.refreshRelationshipGraph = refreshGraph;

    console.log('IPMdb Relationship Explorer ready.', {
        assets: graph.assets.length,
        relationships: graph.relationships.length
    });
})();
