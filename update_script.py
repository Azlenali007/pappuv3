with open('admin/import-services.php', 'r') as f:
    content = f.read()

start_marker = 'function serviceImporter() {'
end_marker = '</script>'

idx_start = content.find(start_marker)
idx_end = content.find(end_marker)

if idx_start == -1 or idx_end == -1:
    print('ERROR: markers not found')
    exit(1)

new_js = """function serviceImporter() {
    return {
        selectedProviderId: '<?= (int)$preselectedProviderId ?>',
        providerName: '',
        providerCurrency: 'USD',
        baseCurrency: '<?= e(app_base_currency_code()) ?>',
        baseCurrencySymbol: '<?= e(app_base_currency()) ?>',
        conversionRateToBase: 1.0,
        markupPercent: 25,
        markupFixed: 0,
        defaultCategoryId: '<?= (int)($categories[0][\\'id\\'] ?? 1) ?>',
        loading: false,
        importing: false,
        categories: INITIAL_CATEGORIES,
        services: [],
        providerCategories: [],
        selectedIds: [],
        searchQuery: '',
        categoryFilter: 'all',
        statusFilter: 'all',
        currentPage: 1,
        perPage: 50,

        onProviderSelect() {
            this.services = [];
            this.providerCategories = [];
            this.selectedIds = [];
        },

        onCategoryMappingChange(catName, targetCatId) {
            const parsedTarget = (targetCatId === 'auto_create') ? 'auto_create' : parseInt(targetCatId, 10);
            this.services.forEach(s => {
                if (s.category_name === catName) {
                    s.matched_category_id = parsedTarget;
                }
            });
            const pCat = this.providerCategories.find(c => c.name === catName);
            if (pCat) pCat.matched_category_id = parsedTarget;
        },

        calculateRate(originalRate) {
            const orig = parseFloat(originalRate) || 0;
            if (orig <= 0) return '0.00';
            const origInBase = orig * this.conversionRateToBase;
            const pct = parseFloat(this.markupPercent) || 0;
            const fixed = parseFloat(this.markupFixed) || 0;
            const res = origInBase + (origInBase * (pct / 100)) + fixed;
            return res.toFixed(2);
        },

        get uniqueCategories() {
            const set = new Set();
            this.services.forEach(s => {
                if (s.category_name) set.add(s.category_name);
            });
            return Array.from(set).sort();
        },

        get filteredServices() {
            return this.services.filter(s => {
                if (this.statusFilter === 'new' && s.already_imported) return false;
                if (this.statusFilter === 'imported' && !s.already_imported) return false;
                if (this.categoryFilter !== 'all' && s.category_name !== this.categoryFilter) return false;
                if (this.searchQuery.trim() !== '') {
                    const q = this.searchQuery.toLowerCase();
                    const matchName = s.name.toLowerCase().includes(q);
                    const matchId = String(s.provider_service_id).includes(q);
                    if (!matchName && !matchId) return false;
                }
                return true;
            });
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredServices.length / this.perPage));
        },

        get paginatedServices() {
            const start = (this.currentPage - 1) * this.perPage;
            return this.filteredServices.slice(start, start + this.perPage);
        },

        get isAllFilteredSelected() {
            const filtered = this.filteredServices;
            if (filtered.length === 0) return false;
            const set = new Set(this.selectedIds.map(String));
            return filtered.every(s => set.has(String(s.provider_service_id)));
        },

        toggleSelectService(psid) {
            const idStr = String(psid);
            const idx = this.selectedIds.indexOf(idStr);
            if (idx > -1) {
                this.selectedIds.splice(idx, 1);
            } else {
                this.selectedIds.push(idStr);
            }
        },

        toggleSelectAll(checked) {
            if (checked) {
                this.selectAllFiltered();
            } else {
                this.deselectAll();
            }
        },

        selectAllFiltered() {
            const idsToAdd = this.filteredServices.map(s => String(s.provider_service_id));
            this.selectedIds = Array.from(new Set([...this.selectedIds, ...idsToAdd]));
        },

        selectOnlyNew() {
            const newIds = this.filteredServices
                .filter(s => !s.already_imported)
                .map(s => String(s.provider_service_id));
            this.selectedIds = newIds;
        },

        deselectAll() {
            this.selectedIds = [];
        },

        async fetchServices() {
            if (!this.selectedProviderId) {
                Swal.fire({ icon: 'warning', title: 'Provider Required', text: 'Please select a provider first.' });
                return;
            }

            this.loading = true;
            this.services = [];
            this.providerCategories = [];
            this.selectedIds = [];
            this.currentPage = 1;

            try {
                const formData = new FormData();
                formData.append('action', 'fetch_services');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('ajax', '1');

                const res = await fetch('/admin/import-services.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();

                if (data.success) {
                    this.services = (data.services || []).map(s => ({
                        ...s,
                        provider_service_id: String(s.provider_service_id)
                    }));
                    this.providerCategories = data.categories || [];
                    this.providerName = data.provider_name || 'Selected Provider';
                    this.providerCurrency = data.provider_currency || 'USD';
                    this.baseCurrency = data.base_currency || 'INR';
                    this.baseCurrencySymbol = data.base_currency_symbol || '₹';
                    this.conversionRateToBase = Number(data.conversion_rate_to_base) || 1.0;

                    if (Array.isArray(data.existing_categories)) {
                        this.categories = data.existing_categories;
                    }

                    if (this.services.length === 0) {
                        Swal.fire({
                            icon: 'info',
                            title: 'No Services Returned',
                            text: 'The provider API returned an empty list of services.'
                        });
                    } else {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `Loaded ${data.total_count} services across ${this.providerCategories.length} categories`,
                            showConfirmButton: false,
                            timer: 2500
                        });
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Fetch Services',
                        text: data.error || 'Provider communication failed.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to backend server: ' + err.message
                });
            } finally {
                this.loading = false;
            }
        },

        async importSelected() {
            if (this.selectedIds.length === 0) {
                Swal.fire({ icon: 'info', title: 'No Services Selected', text: 'Please check one or more services to import.' });
                return;
            }

            const confirmed = await Swal.fire({
                title: `Import ${this.selectedIds.length} Service(s)?`,
                text: `Required provider categories will be automatically created or reused in your database, and all selected services will be imported together with a ${this.markupPercent}% profit markup in one action.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Import Now',
                cancelButtonText: 'Cancel'
            });

            if (!confirmed.isConfirmed) return;

            this.importing = true;

            try {
                const selectedSet = new Set(this.selectedIds.map(String));
                const payloadServices = this.services
                    .filter(s => selectedSet.has(String(s.provider_service_id)))
                    .map(s => ({
                        provider_service_id: String(s.provider_service_id),
                        name: s.name,
                        original_rate: Number(s.original_rate) || 0,
                        category_name: (s.category_name || 'Other').trim(),
                        target_category_id: s.matched_category_id,
                        min_quantity: Number(s.min_quantity) || 10,
                        max_quantity: Number(s.max_quantity) || 100000,
                        service_type: s.service_type || 'default',
                        speed: s.speed || 'Fast Delivery',
                        description: s.description || null
                    }));

                const formData = new FormData();
                formData.append('action', 'import_services');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('markup_percent', this.markupPercent);
                formData.append('markup_fixed', this.markupFixed);
                formData.append('default_category_id', this.defaultCategoryId);
                formData.append('services_data', JSON.stringify(payloadServices));
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('ajax', '1');

                const res = await fetch('/admin/import-services.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();

                if (data.success) {
                    // Mark imported services
                    selectedSet.forEach(psid => {
                        const item = this.services.find(s => String(s.provider_service_id) === String(psid));
                        if (item) item.already_imported = true;
                    });
                    this.selectedIds = [];

                    const imported = Number(data.imported ?? data.services_imported ?? 0);
                    const skipped = Number(data.skipped_duplicates ?? data.skipped ?? 0);
                    const failed = Number(data.failed ?? 0);
                    const catsCreated = Number(data.categories_created ?? 0);
                    const catsReused = Number(data.categories_reused ?? 0);

                    if (Array.isArray(data.updated_categories)) {
                        this.categories = data.updated_categories;
                        const updatedCatNamesMap = new Map();
                        this.categories.forEach(c => {
                            updatedCatNamesMap.set(c.name.toLowerCase(), c.id);
                        });
                        this.providerCategories.forEach(pCat => {
                            const lower = pCat.name.toLowerCase();
                            if (updatedCatNamesMap.has(lower)) {
                                pCat.already_imported = true;
                                const localId = updatedCatNamesMap.get(lower);
                                pCat.local_category_id = localId;
                                pCat.matched_category_id = localId;
                            }
                        });
                    }

                    const errorHtml = (data.errors && data.errors.length > 0)
                        ? `<div class="mt-2 text-left max-h-32 overflow-y-auto p-2 bg-rose-50 border border-rose-200 rounded-lg text-[11px] text-rose-800 space-y-1">
                               ${data.errors.slice(0, 10).map(e => `<div>• ${e}</div>`).join('')}
                               ${data.errors.length > 10 ? `<div>...and ${data.errors.length - 10} more</div>` : ''}
                           </div>`
                        : '';

                    Swal.fire({
                        icon: failed > 0 ? (imported > 0 ? 'warning' : 'error') : 'success',
                        title: failed > 0 ? (imported > 0 ? 'Import Completed with Warnings' : 'Import Failed') : 'Import Completed Successfully',
                        html: `
                            <div class="text-left text-xs space-y-2 p-3.5 bg-slate-50 rounded-xl border border-slate-200 mt-2">
                                <div class="font-bold text-slate-800 pb-1 border-b border-slate-200">Services Summary:</div>
                                <div class="flex justify-between"><span>✓ <strong>Successfully Imported:</strong></span> <span class="text-emerald-600 font-bold font-mono">${imported}</span></div>
                                <div class="flex justify-between"><span>• <strong>Skipped (Duplicates):</strong></span> <span class="text-amber-600 font-bold font-mono">${skipped}</span></div>
                                <div class="flex justify-between"><span>✕ <strong>Failed:</strong></span> <span class="text-rose-600 font-bold font-mono">${failed}</span></div>
                                
                                <div class="font-bold text-slate-800 pt-2 pb-1 border-b border-slate-200 border-t">Categories Summary:</div>
                                <div class="flex justify-between"><span>+ <strong>Categories Created:</strong></span> <span class="text-blue-600 font-bold font-mono">${catsCreated}</span></div>
                                <div class="flex justify-between"><span>↻ <strong>Categories Reused/Matched:</strong></span> <span class="text-slate-600 font-bold font-mono">${catsReused}</span></div>
                            </div>
                            ${errorHtml}
                        `,
                        confirmButtonText: 'Done'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Import Failed',
                        text: data.error || 'Failed to complete database insertion.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Import Request Error',
                    text: 'An error occurred while importing: ' + err.message
                });
            } finally {
                this.importing = false;
            }
        }
    };
}
"""

new_content = content[:idx_start] + new_js + content[idx_end:]
with open('admin/import-services.php', 'w') as f:
    f.write(new_content)

print('SUCCESS')
