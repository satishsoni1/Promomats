<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Upload Document') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="space-y-5"
                      @submit="checkStakeholders($event)"
                      x-data="{
                          projects: @js($projects->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name, 'target_audience' => $p->target_audience])->values()),
                          templates: @js($templates->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'team' => $t->team ?? 'pharma', 'target_audiences' => $t->target_audiences ?? []])->values()),
                          brands: @js($brands->map(fn ($b) => ['id' => (string) $b->id, 'name' => $b->name, 'hint' => $b->code])->values()),
                          // Pharma / Scientific Publications: each team its own workflows and fields.
                          team: @js(old('team', $defaultTeam)),
                          get teamTemplates() { return this.templates.filter(t => t.team === this.team); },
                          get brandOptions() { return this.brands; },
                          get projectOptions() { return this.projects; },
                          setTeam(team) {
                              if (this.team === team) return;
                              this.team = team;
                              if (! this.teamTemplates.some(t => t.id === this.workflowId)) { this.workflowId = ''; this.suggested = false; }
                              this.resetEditor();
                              if (this.teamTemplates.length === 1) this.workflowId = this.teamTemplates[0].id;
                          },
                          rules: @js($workflowRules->map(fn ($r) => ['workflow_template_id' => (string) $r->workflow_template_id, 'brand_id' => $r->brand_id ? (string) $r->brand_id : null, 'document_type_id' => $r->document_type_id ? (string) $r->document_type_id : null, 'priority' => $r->priority])->values()),
                          // Per-stage candidate approvers, keyed by workflow_template_id, only
                          // for templates whose admin enabled owner customization. Drives the
                          // 'Approvers for this workflow' section below.
                          templateApprovers: @js($templateApproverOptions),
                          guidanceMap: @js(\App\Support\TargetAudience::GUIDANCE),
                          audience: @js(old('target_audience', '')),
                          projectId: @js(old('project_id', '')),
                          workflowId: @js(old('workflow_template_id', '')),
                          brandId: @js(old('brand_id', '')),
                          documentTypeId: @js(old('document_type_id', '')),
                          channel: @js(old('channel', '')),
                          placeholder: @js((bool) old('is_placeholder', false)),
                          creativeTeam: @js(old('creative_team', 'later')),
                          docTypes: @js($documentTypes->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'channel' => $t->channel])->values()),
                          defaultDueHours: @js($defaultDueHours),
                          skus: @js($skus->map(fn ($k) => ['id' => (string) $k->id, 'brand_id' => (string) $k->brand_id, 'name' => $k->name . ($k->code ? ' (' . $k->code . ')' : '')])->values()),
                          skuId: @js((string) old('sku_id', '')),
                          get skuOptions() {
                              return this.brandId ? this.skus.filter(k => k.brand_id === this.brandId) : [];
                          },
                          onBrandChange() {
                              if (! this.skuOptions.some(k => k.id === this.skuId)) this.skuId = '';
                              this.onBrandOrTypeChange();
                          },
                          // MOM 24 Nov: overall due date worked out from the stages in play
                          // (stages with nobody ticked are skipped; parallel tracks overlap).
                          dueTick: 0,
                          get computedDue() {
                              this.dueTick;
                              const stages = this.templateApprovers[this.workflowId];
                              if (! stages || ! this.$root) return null;
                              const form = this.$root;
                              const picked = id => form.querySelectorAll('input[name=\'stage_approvers[' + id + '][]\']:checked').length > 0;
                              const anyPicks = stages.some(s => picked(s.stage_id));
                              const hoursOf = s => {
                                  const el = form.querySelector('input[name=\'stage_due_hours[' + s.stage_id + ']\']');
                                  return parseInt(el?.value || s.due_hours || this.defaultDueHours, 10) || 0;
                              };
                              const columns = [];
                              stages.forEach(s => {
                                  if (anyPicks && ! picked(s.stage_id) && ! s.is_chairperson) return;
                                  const key = s.parallel_group || ('solo-' + s.stage_id);
                                  const col = columns.find(c => c.key === key);
                                  col ? col.hours = Math.max(col.hours, hoursOf(s)) : columns.push({ key, hours: hoursOf(s) });
                              });
                              if (! columns.length) return null;
                              const total = columns.reduce((t, c) => t + c.hours, 0);
                              const due = new Date(Date.now() + total * 3600 * 1000);
                              return due.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' IST';
                          },
                          // Chairperson: the brand's division approver (Zeal -> Rathna, Zandra -> Jalba).
                          chairpersonByBrand: @js((object) $chairpersonByBrand),
                          defaultIdsFor(stage) {
                              if (stage.is_chairperson && this.brandId && this.chairpersonByBrand[this.brandId]) {
                                  return [this.chairpersonByBrand[this.brandId]];
                              }
                              return stage.default_ids || [];
                          },
                          // Pharma: at least one R&D, Legal and Regulatory Affairs stakeholder.
                          required: @js($requiredStakeholders),
                          get requiredGroups() { return (this.required[this.team] || {}).groups || []; },
                          get selectedIds() {
                              this.dueTick;
                              const stages = this.templateApprovers[this.workflowId] || [];
                              if (this.editor.on) {
                                  return this.editor.stages.filter(st => ! st.removed).flatMap(st => st.approverUserIds.map(String));
                              }
                              if (! this.$root) return [];
                              const ticked = Array.from(this.$root.querySelectorAll('input[name^=\'stage_approvers[\']:checked')).map(el => String(el.value));
                              // Nothing ticked anywhere: every stage goes to its usual people.
                              return ticked.length ? ticked : stages.flatMap(s => s.candidates.map(c => String(c.id)));
                          },
                          get stakeholderStatus() {
                              const req = this.required[this.team] || {};
                              const covered = new Set();
                              this.selectedIds.forEach(id => ((req.users || {})[id] || []).forEach(g => covered.add(g)));
                              if (this.editor.on) {
                                  this.editor.stages.filter(st => ! st.removed).forEach(st => (st.approverRoleIds || [])
                                      .forEach(rid => ((req.roles || {})[rid] || []).forEach(g => covered.add(g))));
                              }
                              return this.requiredGroups.map(g => ({ group: g, ok: covered.has(g) }));
                          },
                          get missingStakeholders() { return this.stakeholderStatus.filter(s => ! s.ok).map(s => s.group); },
                          checkStakeholders(e) {
                              if (! this.canCustomise || ! this.missingStakeholders.length) return;
                              e.preventDefault();
                              this.$refs.stakeholderCheck?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                          },
                          get typeOptions() {
                              return this.docTypes.filter(t => this.channel ? t.channel === this.channel : true);
                          },
                          onChannelChange() {
                              const current = this.docTypes.find(t => t.id === this.documentTypeId);
                              if (current && this.channel && current.channel !== this.channel) this.documentTypeId = '';
                              this.onBrandOrTypeChange();
                          },
                          addExtra(stage, id) {
                              if (! id) return;
                              stage.extra = stage.extra || [];
                              const already = stage.extra.some(u => String(u.id) === String(id)) || stage.candidates.some(c => String(c.id) === String(id));
                              const user = this.allUsers.find(u => String(u.id) === String(id));
                              if (user && ! already) stage.extra.push(user);
                          },
                          suggested: false,
                          onProjectChange() {
                              const p = this.projects.find(pr => pr.id === this.projectId);
                              if (p && p.target_audience && ! this.audience) {
                                  this.audience = p.target_audience;
                                  this.onAudienceChange();
                              }
                          },
                          onAudienceChange() {
                              this.suggested = false;
                              if (! this.workflowId) {
                                  const match = this.teamTemplates.find(t => (t.target_audiences || []).includes(this.audience));
                                  if (match) { this.workflowId = match.id; this.suggested = true; }
                              }
                          },
                          // Mirrors App\Services\Workflow\WorkflowResolver client-side (brand/type
                          // only - department isn't known here) so the form can suggest a workflow
                          // the moment both are picked, without a round trip. store() re-resolves
                          // authoritatively on submit either way.
                          onBrandOrTypeChange() {
                              this.suggested = false;
                              if (this.team !== 'pharma' || (! this.brandId && ! this.documentTypeId)) return;
                              const matches = this.rules.filter(r => this.teamTemplates.some(t => t.id === r.workflow_template_id) &&
                                  (r.brand_id === null || r.brand_id === this.brandId) &&
                                  (r.document_type_id === null || r.document_type_id === this.documentTypeId)
                              );
                              if (! matches.length) return;
                              const specificity = r => (r.brand_id !== null ? 1 : 0) + (r.document_type_id !== null ? 1 : 0);
                              matches.sort((a, b) => (a.priority - specificity(a) * 0.1) - (b.priority - specificity(b) * 0.1));
                              this.workflowId = matches[0].workflow_template_id;
                              this.suggested = true;
                          },

                          // --- Owner customisation of the approval flow --------------------
                          allUsers: @js($allUsers),
                          allRoles: @js($allRoles),
                          editor: { on: false, stages: [] },
                          get canCustomise() { return !!(this.workflowId && this.templateApprovers[this.workflowId]); },
                          resetEditor() { this.editor.on = false; this.editor.stages = []; },
                          openEditor() {
                              this.editor.stages = (this.templateApprovers[this.workflowId] || []).map(s => ({
                                  code: s.code, name: s.name, isNew: false, removed: false,
                                  parallel_group: s.parallel_group, isFinal: s.is_final,
                                  candidates: s.candidates,
                                  approverUserIds: s.candidates.map(c => String(c.id)),
                                  approversDirty: false,
                                  approvalMode: 'any_one', approverRoleIds: [],
                              }));
                              this.editor.on = true;
                          },
                          addStage() {
                              this.editor.stages.push({
                                  code: null, name: '', isNew: true, removed: false,
                                  parallel_group: null, isFinal: false, candidates: [],
                                  approverUserIds: [], approversDirty: false,
                                  approvalMode: 'any_one', approverRoleIds: [],
                              });
                          },
                          move(i, dir) {
                              const j = i + dir, s = this.editor.stages;
                              if (j < 0 || j >= s.length) return;
                              [s[i], s[j]] = [s[j], s[i]];
                          },
                          get workflowEditJson() {
                              if (! this.editor.on) return '';
                              return JSON.stringify({ stages: this.editor.stages.map(st => ({
                                  code: st.code, name: st.name, isNew: st.isNew, removed: st.removed,
                                  approversDirty: st.approversDirty,
                                  approverUserIds: st.approverUserIds,
                                  approverRoleIds: st.approverRoleIds,
                                  approvalMode: st.approvalMode,
                              })) });
                          },
                      }">
                    @csrf

                    {{-- Team: Pharma and Scientific Publications each have their own workflows and fields. --}}
                    <div>
                        <x-input-label value="Team" />
                        <input type="hidden" name="team" :value="team">
                        <div class="mt-1 grid grid-cols-1 sm:grid-cols-{{ count($teams) }} gap-2">
                            @foreach ($teams as $teamKey => $teamCfg)
                                <button type="button" @click="setTeam('{{ $teamKey }}')"
                                        :class="team === '{{ $teamKey }}' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-gray-200 hover:border-gray-300'"
                                        class="text-left rounded-lg border px-3 py-2.5 transition">
                                    <span class="block text-sm font-semibold text-gray-900">{{ $teamCfg['label'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $teamCfg['description'] ?? '' }}</span>
                                </button>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('team')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="title" value="Title" />
                        <x-text-input id="title" name="title" class="mt-1 block w-full" :value="old('title')" required autofocus />
                        <x-input-error :messages="$errors->get('title')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" value="Description" />
                        <textarea id="description" name="description" rows="3" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">{{ old('description') }}</textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    {{-- ---------------- Pharma fields ---------------- --}}
                    <div x-show="team === 'pharma'" class="space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label value="Brand" />
                                <x-search-select class="mt-1" options="brandOptions" model="brandId" name="brand_id"
                                                 on-change="brandId = id; onBrandChange()" placeholder="Search brand…" />
                                <x-input-error :messages="$errors->get('brand_id')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label value="SKU" />
                                <x-search-select class="mt-1" options="skuOptions" model="skuId" name="sku_id" disabled="! brandId"
                                                 placeholder="Search SKU…" />
                                <p class="mt-1 text-xs text-gray-500" x-text="! brandId ? 'Select a brand first - only its SKUs are listed.' : (skuOptions.length ? skuOptions.length + ' SKU(s) for this brand.' : 'No SKUs for this brand yet - ask an admin to add them.')"></p>
                                <x-input-error :messages="$errors->get('sku_id')" class="mt-2" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <x-input-label for="category" value="Category" />
                                <select id="category" name="category" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                    <option value="">— Select —</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('category')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="channel" value="Collateral classification" />
                                <select id="channel" name="channel" x-model="channel" @change="onChannelChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                    <option value="">— Select —</option>
                                    <option value="print">Print</option>
                                    <option value="digital">Digital</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="document_type_id" value="Type of collateral" />
                                <select id="document_type_id" name="document_type_id" x-model="documentTypeId" @change="onBrandOrTypeChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                    <option value="">— Not specified —</option>
                                    <template x-for="t in typeOptions" :key="t.id">
                                        <option :value="t.id" x-text="t.name + (! channel && t.channel ? ' (' + (t.channel === 'print' ? 'Print' : 'Digital') + ')' : '')" :selected="t.id === documentTypeId"></option>
                                    </template>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">LBL, LBC, Retailer Poster, VAF, Dangler…</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label value="Country" />
                                <x-search-select class="mt-1" placeholder="Search country…">
                                    <select name="country_id">
                                        <option value="">— Select —</option>
                                        @foreach ($countries as $country)
                                            <option value="{{ $country->id }}" @selected(old('country_id') == $country->id)>{{ $country->name }}{{ $country->code ? ' (' . $country->code . ')' : '' }}</option>
                                        @endforeach
                                    </select>
                                </x-search-select>
                                <p class="mt-1 text-xs text-gray-500">Drives the reference number prefix.</p>
                                <x-input-error :messages="$errors->get('country_id')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="target_audience" value="Target Audience" />
                                <select id="target_audience" name="target_audience" x-model="audience" @change="onAudienceChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                    <option value="">— Not specified —</option>
                                    @foreach (\App\Support\TargetAudience::LABELS as $key => $label)
                                        <option value="{{ $key }}" @selected(old('target_audience') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Who this content ultimately reaches — used to suggest a workflow and the right review depth.</p>
                            </div>
                        </div>

                        <template x-if="audience && guidanceMap[audience]">
                            <div class="rounded-md p-3 text-xs border flex items-start gap-2"
                                 :class="{
                                     'bg-red-50 border-red-200 text-red-800': guidanceMap[audience].depth === 'high',
                                     'bg-amber-50 border-amber-200 text-amber-800': guidanceMap[audience].depth === 'medium',
                                     'bg-gray-50 border-gray-200 text-gray-600': guidanceMap[audience].depth === 'low',
                                 }">
                                <span class="text-sm leading-none" x-text="guidanceMap[audience].depth === 'high' ? '⚠️' : (guidanceMap[audience].depth === 'medium' ? '◐' : 'ℹ️')"></span>
                                <div>
                                    <p class="font-semibold" x-text="guidanceMap[audience].depth === 'high' ? 'Deep review required' : (guidanceMap[audience].depth === 'medium' ? 'Standard review' : 'Lightweight review')"></p>
                                    <p class="mt-0.5" x-text="guidanceMap[audience].message"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- ---------------- Scientific Publications fields ----------------
                         The team's own fields are added here as they are agreed
                         (config/promomats.php 'teams.publication.fields'). --}}
                    <div x-show="team === 'publication'" x-cloak class="rounded-md border border-indigo-100 bg-indigo-50/40 p-3 text-xs text-indigo-900">
                        Scientific Publications job — pick the publication below and one of the team's workflows. Brand, SKU and collateral fields don't apply.
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="workflow_template_id" value="Approval Workflow" />
                            <select id="workflow_template_id" name="workflow_template_id" x-model="workflowId" @change="suggested = false; resetEditor()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                <option value="" x-text="team === 'pharma' ? 'Auto-assign from Brand / Document Type…' : '— Select a workflow —'"></option>
                                <template x-for="t in teamTemplates" :key="t.id">
                                    <option :value="t.id" x-text="t.name" :selected="t.id === workflowId"></option>
                                </template>
                            </select>
                            <p class="mt-1 text-xs text-brand-600" x-show="suggested" x-cloak>Suggested based on Brand / Document Type / target audience — change if needed.</p>
                            <p class="mt-1 text-xs text-gray-500" x-show="! workflowId && team === 'pharma'" x-cloak>Leave blank to resolve automatically from Brand + Document Type on submit; if none matches, you'll be asked to pick one.</p>
                            <p class="mt-1 text-xs text-gray-500" x-show="! teamTemplates.length" x-cloak>No active workflow for this team yet — ask an admin.</p>
                            <x-input-error :messages="$errors->get('workflow_template_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label x-text="team === 'publication' ? 'Publication' : 'Project (optional)'" value="Project (optional)" />
                            <x-search-select class="mt-1" options="projectOptions" model="projectId" name="project_id"
                                             on-change="projectId = id; onProjectChange()" placeholder="Search…" />
                            <p class="mt-1 text-xs text-gray-500" x-text="team === 'publication' ? 'The publication this job belongs to.' : 'Groups this document with others under the same initiative. Can be changed later.'"></p>
                            <x-input-error :messages="$errors->get('project_id')" class="mt-2" />
                        </div>
                    </div>

                    {{-- Owner customisation of the approval flow. Shown only for a chosen
                         workflow whose admin enabled it. Two modes:
                         · default  — tick/untick each stage's candidate people (posts stage_approvers[])
                         · editor   — add / remove / reorder stages for this document (posts workflow_edit JSON) --}}
                    <template x-if="canCustomise">
                        <div class="rounded-md border border-brand-200 bg-brand-50/50 p-4 space-y-4">

                            {{-- ---- default: approver picking ---- --}}
                            <template x-if="! editor.on">
                                <div class="space-y-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-gray-800">Stakeholders &amp; due dates for each stage</p>
                                            <p class="text-xs text-gray-500 mt-0.5">Tick the person (or people) who should take each stage — e.g. Jalba or Rathna for Content Manager, the right TM/AGM, this brand's R&amp;D / Regulatory Affairs / Legal reviewers. Add anyone else if needed. Stages you leave with nobody ticked are skipped and hidden from the approval matrix. Each stage gets {{ $defaultDueHours }} hours by default. You own this job, so you can't be picked as a reviewer.</p>
                                        </div>
                                        <button type="button" class="shrink-0 text-xs px-2.5 py-1 bg-white border border-brand-300 text-brand-700 rounded-md hover:bg-brand-50" @click="openEditor()">Edit stages…</button>
                                    </div>
                                    <template x-for="stage in templateApprovers[workflowId]" :key="stage.stage_id">
                                        <div class="rounded border border-gray-200 bg-white p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <span class="text-sm font-medium text-gray-700">
                                                    <span x-text="stage.sequence_no + '. ' + stage.name"></span>
                                                    <span x-show="stage.parallel_group" class="ml-1 text-[10px] uppercase tracking-wide text-brand-600">parallel</span>
                                                    <span x-show="stage.is_draft" class="ml-1 text-[10px] uppercase tracking-wide text-violet-600">draft</span>
                                                    <span x-show="stage.is_chairperson" class="ml-1 text-[10px] uppercase tracking-wide text-amber-600">auto-selected</span>
                                                </span>
                                                <label class="flex items-center gap-1.5 text-xs text-gray-500">
                                                    Due in
                                                    <input type="number" min="1" max="2160" :name="'stage_due_hours[' + stage.stage_id + ']'" :value="stage.due_hours || defaultDueHours"
                                                           @input="dueTick++" class="w-16 text-xs border-gray-300 rounded-md py-1">
                                                    hours
                                                </label>
                                            </div>
                                            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                                <template x-for="cand in stage.candidates" :key="cand.id">
                                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                                        <input type="checkbox"
                                                               :name="'stage_approvers[' + stage.stage_id + '][]'"
                                                               :value="cand.id"
                                                               :checked="stage.candidates.length === 1 || defaultIdsFor(stage).includes(cand.id)"
                                                               @change="dueTick++"
                                                               class="rounded border-gray-300 text-brand-600">
                                                        <span x-text="cand.name"></span>
                                                    </label>
                                                </template>
                                                <template x-for="u in (stage.extra || [])" :key="'x' + u.id">
                                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                                        <input type="checkbox" :name="'stage_approvers[' + stage.stage_id + '][]'" :value="u.id" checked @change="dueTick++" class="rounded border-gray-300 text-brand-600">
                                                        <span x-text="u.name"></span>
                                                        <span class="text-[10px] uppercase tracking-wide text-emerald-600">added</span>
                                                    </label>
                                                </template>
                                            </div>
                                            <div class="mt-2 flex items-center gap-2">
                                                <x-search-select size="sm" class="w-64 max-w-full shrink-0" options="allUsers" clear-on-pick
                                                                 on-pick="addExtra(stage, id); dueTick++" placeholder="+ Add someone else (search)…" />
                                                <span class="text-[11px] text-gray-400" x-text="stage.parallel_group
                                                    ? 'MLR stakeholders run in parallel. Only the tracks you tick someone for are sent.'
                                                    : (stage.is_chairperson ? 'The Chairperson approver is selected automatically — change it if needed.' : 'Nobody ticked: this stage is skipped and hidden from the approval matrix.')"></span>
                                            </div>
                                        </div>
                                    </template>
                                    <x-input-error :messages="$errors->get('stage_approvers')" class="mt-1" />
                                    <div class="flex items-center justify-between rounded-md bg-white border border-gray-200 px-3 py-2 text-sm" x-init="$nextTick(() => dueTick++)">
                                        <span class="text-gray-600">Overall due date <span class="text-xs text-gray-400">(worked out from the stages selected)</span></span>
                                        <span class="font-medium text-gray-900" x-text="computedDue || '—'"></span>
                                    </div>
                                </div>
                            </template>

                            {{-- ---- editor: add / remove / reorder stages ---- --}}
                            <template x-if="editor.on">
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-semibold text-gray-800">Edit approval flow <span class="font-normal text-gray-400">— this document only</span></p>
                                        <button type="button" class="text-xs text-gray-500 hover:underline" @click="resetEditor()">Use standard flow</button>
                                    </div>

                                    <template x-for="(st, i) in editor.stages" :key="i">
                                        <div class="rounded border bg-white p-3" :class="st.removed ? 'border-red-200 opacity-60' : 'border-gray-200'">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="text-xs text-gray-400" x-text="(i + 1) + '.'"></span>
                                                    <template x-if="st.isNew">
                                                        <input type="text" x-model="st.name" placeholder="New stage name" class="text-sm border-gray-300 rounded-md py-1 w-60">
                                                    </template>
                                                    <template x-if="! st.isNew">
                                                        <span class="text-sm font-medium text-gray-700" x-text="st.name"></span>
                                                    </template>
                                                    <span x-show="st.parallel_group" class="text-[10px] uppercase tracking-wide text-brand-600">parallel</span>
                                                    <span x-show="st.isNew" class="text-[10px] uppercase tracking-wide text-emerald-600">new</span>
                                                    <span x-show="st.removed" class="text-[10px] uppercase tracking-wide text-red-500">removed</span>
                                                </div>
                                                <div class="flex items-center gap-1 shrink-0 text-sm">
                                                    <button type="button" class="px-1.5 text-gray-500 hover:text-gray-900 disabled:opacity-30" @click="move(i, -1)" :disabled="i === 0">↑</button>
                                                    <button type="button" class="px-1.5 text-gray-500 hover:text-gray-900 disabled:opacity-30" @click="move(i, 1)" :disabled="i === editor.stages.length - 1">↓</button>
                                                    <button type="button" class="px-1.5 text-red-500 hover:text-red-700 text-xs" x-show="! st.removed" @click="st.removed = true">Remove</button>
                                                    <button type="button" class="px-1.5 text-gray-500 hover:underline text-xs" x-show="st.removed" @click="st.removed = false">Undo</button>
                                                </div>
                                            </div>

                                            <div x-show="! st.removed" class="mt-2">
                                                <template x-if="! st.isNew">
                                                    <div class="grid grid-cols-2 gap-1.5">
                                                        <template x-for="cand in st.candidates" :key="cand.id">
                                                            <label class="flex items-center gap-2 text-sm text-gray-600">
                                                                <input type="checkbox" :value="String(cand.id)" x-model="st.approverUserIds"
                                                                       @change="st.approversDirty = true"
                                                                       :disabled="st.candidates.length === 1"
                                                                       class="rounded border-gray-300 text-brand-600">
                                                                <span x-text="cand.name"></span>
                                                            </label>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="st.isNew">
                                                    <div class="space-y-2">
                                                        <label class="flex items-center gap-2 text-xs text-gray-500">Mode
                                                            <select x-model="st.approvalMode" class="text-xs border-gray-300 rounded-md py-1">
                                                                <option value="any_one">Any one approver</option>
                                                                <option value="all_required">All approvers required</option>
                                                                <option value="majority">Majority</option>
                                                            </select>
                                                        </label>
                                                        <div>
                                                            <p class="text-xs text-gray-500 mb-1">Assign to role(s)</p>
                                                            <div class="grid grid-cols-3 gap-1">
                                                                <template x-for="r in allRoles" :key="r.id">
                                                                    <label class="flex items-center gap-1.5 text-xs text-gray-600">
                                                                        <input type="checkbox" :value="r.id" x-model.number="st.approverRoleIds" class="rounded border-gray-300 text-brand-600">
                                                                        <span x-text="r.name"></span>
                                                                    </label>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <p class="text-xs text-gray-500 mb-1">…and / or specific people</p>
                                                            <div class="flex flex-wrap gap-1 mb-1" x-show="st.approverUserIds.length">
                                                                <template x-for="uid in st.approverUserIds" :key="uid">
                                                                    <span class="inline-flex items-center gap-1 text-xs bg-brand-50 text-brand-800 rounded-full pl-2 pr-1 py-0.5">
                                                                        <span x-text="(allUsers.find(u => String(u.id) === String(uid)) || {}).name"></span>
                                                                        <button type="button" class="text-brand-500 hover:text-brand-800" @click="st.approverUserIds = st.approverUserIds.filter(x => x !== uid)">&times;</button>
                                                                    </span>
                                                                </template>
                                                            </div>
                                                            <x-search-select size="sm" options="allUsers.filter(u => ! st.approverUserIds.includes(String(u.id)))" clear-on-pick
                                                                             on-pick="st.approverUserIds.push(String(id))" placeholder="Search people to add…" />
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <button type="button" class="text-xs px-3 py-1.5 bg-white border border-brand-300 text-brand-700 rounded-md hover:bg-brand-50" @click="addStage()">+ Add stage</button>
                                    <x-input-error :messages="$errors->get('workflow_edit')" class="mt-1" />
                                </div>
                            </template>

                            {{-- Pharma: R&D, Legal and Regulatory Affairs must each have someone. --}}
                            <div x-show="requiredGroups.length" x-ref="stakeholderCheck"
                                 :class="missingStakeholders.length ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'"
                                 class="rounded-md border px-3 py-2 text-sm">
                                <p class="font-medium" :class="missingStakeholders.length ? 'text-red-700' : 'text-emerald-700'"
                                   x-text="missingStakeholders.length
                                       ? 'Select at least one stakeholder from each of: ' + requiredGroups.join(', ')
                                       : 'Required stakeholders selected'"></p>
                                <div class="mt-1 flex flex-wrap gap-3 text-xs">
                                    <template x-for="s in stakeholderStatus" :key="s.group">
                                        <span :class="s.ok ? 'text-emerald-700' : 'text-red-600'" x-text="(s.ok ? '✓ ' : '✗ ') + s.group"></span>
                                    </template>
                                </div>
                            </div>

                            <input type="hidden" name="workflow_edit" :value="workflowEditJson">
                        </div>
                    </template>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <x-input-label value="Task due date" />
                            <p class="mt-2 text-sm text-gray-700" x-text="computedDue || 'Worked out from the stages'"></p>
                            <p class="mt-1 text-[11px] text-gray-500">Automatic. Change any stage's due date/time on the job page.</p>
                        </div>
                        <div>
                            <x-input-label for="start_date" value="Start Date" />
                            <x-text-input type="date" id="start_date" name="start_date" class="mt-1 block w-full" :value="old('start_date')" />
                        </div>
                        <div>
                            <x-input-label for="expiry_date" value="Expiry Date" />
                            <x-text-input type="date" id="expiry_date" name="expiry_date" class="mt-1 block w-full" :value="old('expiry_date')" />
                        </div>
                        <div>
                            <x-input-label for="aging_warning_days" value="Aging Warning (days before expiry)" />
                            <x-text-input type="number" id="aging_warning_days" name="aging_warning_days" class="mt-1 block w-full" :value="old('aging_warning_days', 30)" />
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="flex items-start gap-2.5 rounded-md border border-gray-200 p-3 cursor-pointer has-[:checked]:border-violet-300 has-[:checked]:bg-violet-50/50">
                            <input type="checkbox" name="is_placeholder" value="1" x-model="placeholder" class="mt-0.5 rounded border-gray-300 text-violet-600">
                            <span>
                                <span class="block text-sm font-medium text-gray-800">Placeholder — no file yet</span>
                                <span class="block text-xs text-gray-500">Creates the job without a file. Upload the Word draft later for Content Manager alignment, or hand the brief to the Content Agency / Internal Design Team. Their upload always comes back to you.</span>
                            </span>
                        </label>

                        <div x-show="placeholder" x-cloak class="space-y-3">
                            <div>
                                <x-input-label for="creative_team" value="Who prepares the creative?" />
                                <select id="creative_team" name="creative_team" x-model="creativeTeam" class="mt-1 block w-full text-sm border-gray-300 rounded-md">
                                    <option value="later" @selected(old('creative_team', 'later') === 'later')>Decide later — I'll upload the Word draft for Content Manager alignment first</option>
                                    <option value="agency" @selected(old('creative_team') === 'agency')>Send the brief to the Content Agency now</option>
                                    <option value="design" @selected(old('creative_team') === 'design')>Send the brief to the Internal Design Team now</option>
                                </select>
                            </div>
                            @if (($agencies ?? collect())->isNotEmpty())
                                <div x-data="{ agencyId: @js((string) old('content_agency_id', '')) }" x-show="creativeTeam === 'agency'" x-cloak class="grid grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label for="content_agency_id" value="Content agency" />
                                        <select id="content_agency_id" name="content_agency_id" x-model="agencyId" class="mt-1 block w-full text-sm border-gray-300 rounded-md">
                                            <option value="">— Select agency —</option>
                                            @foreach ($agencies as $agency)
                                                <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('content_agency_id')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="creative_assign_to" value="Person at the agency" />
                                        <select id="creative_assign_to" name="creative_assign_to" class="mt-1 block w-full text-sm border-gray-300 rounded-md">
                                            <option value="">Whole agency</option>
                                            @foreach ($agencies as $agency)
                                                @foreach ($agency->users as $member)
                                                    <option value="{{ $member->id }}" x-show="agencyId === '{{ $agency->id }}'">{{ $member->name }}</option>
                                                @endforeach
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            @endif
                            <div>
                                <x-input-label for="design_instructions" value="Brief" />
                                <textarea id="design_instructions" name="design_instructions" rows="3" class="mt-1 block w-full text-sm border-gray-300 rounded-md" placeholder="Sizes, key message, claims to use, reference files…">{{ old('design_instructions') }}</textarea>
                            </div>
                        </div>

                        <div x-show="! placeholder">
                            <x-input-label for="file" value="File" />
                            <div class="mt-1" x-effect="$el.querySelector('input[type=file]').required = ! placeholder">
                                <x-file-dropzone id="file" name="file" hint="Any file type accepted, up to 500MB." />
                            </div>
                        </div>
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />

                        <div>
                            <x-input-label for="reference_files" value="Reference documents (optional)" />
                            <input type="file" id="reference_files" name="reference_files[]" multiple
                                   class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200">
                            <p class="mt-1 text-xs text-gray-500">Studies, sources or the print input brief that support the content — attached to the job for every reviewer. You can add more later.</p>
                            <x-input-error :messages="$errors->get('reference_files')" class="mt-1" />
                            <x-input-error :messages="$errors->get('reference_files.*')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Upload</x-primary-button>
                        <a href="{{ route('documents.index') }}" class="text-sm text-gray-500 hover:underline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
