<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Upload Document') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm ring-1 ring-gray-900/5 rounded-lg p-6">
                <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="space-y-5"
                      x-data="{
                          projects: @js($projects->map(fn ($p) => ['id' => (string) $p->id, 'target_audience' => $p->target_audience])->values()),
                          templates: @js($templates->map(fn ($t) => ['id' => (string) $t->id, 'target_audiences' => $t->target_audiences ?? []])->values()),
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
                                  const match = this.templates.find(t => (t.target_audiences || []).includes(this.audience));
                                  if (match) { this.workflowId = match.id; this.suggested = true; }
                              }
                          },
                          // Mirrors App\Services\Workflow\WorkflowResolver client-side (brand/type
                          // only - department isn't known here) so the form can suggest a workflow
                          // the moment both are picked, without a round trip. store() re-resolves
                          // authoritatively on submit either way.
                          onBrandOrTypeChange() {
                              this.suggested = false;
                              if (! this.brandId && ! this.documentTypeId) return;
                              const matches = this.rules.filter(r =>
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

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="brand_id" value="Brand" />
                            <select id="brand_id" name="brand_id" x-model="brandId" @change="onBrandOrTypeChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                <option value="">— Not specified —</option>
                                @foreach ($brands as $brand)
                                    <option value="{{ $brand->id }}" @selected(old('brand_id') == $brand->id)>{{ $brand->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="document_type_id" value="Document Type" />
                            <select id="document_type_id" name="document_type_id" x-model="documentTypeId" @change="onBrandOrTypeChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                <option value="">— Not specified —</option>
                                @foreach ($documentTypes as $type)
                                    <option value="{{ $type->id }}" @selected(old('document_type_id') == $type->id)>{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="category" value="Category" />
                            <x-text-input id="category" name="category" class="mt-1 block w-full" :value="old('category')" placeholder="e.g. Promotional Material" />
                        </div>
                        <div>
                            <x-input-label for="products" value="Product(s)" />
                            <x-text-input id="products" name="products" class="mt-1 block w-full" :value="old('products')" placeholder="e.g. Immunobooster, Natevba" />
                            <p class="mt-1 text-xs text-gray-500">Comma-separated if more than one.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="countries" value="Country / Countries" />
                            <x-text-input id="countries" name="countries" class="mt-1 block w-full" :value="old('countries')" placeholder="e.g. US, EU, Global" />
                            <p class="mt-1 text-xs text-gray-500">First country drives the reference number prefix.</p>
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

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="workflow_template_id" value="Approval Workflow" />
                            <select id="workflow_template_id" name="workflow_template_id" x-model="workflowId" @change="suggested = false; resetEditor()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                <option value="">Auto-assign from Brand / Document Type…</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected(old('workflow_template_id') == $template->id)>{{ $template->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-600" x-show="suggested" x-cloak>Suggested based on Brand / Document Type / target audience — change if needed.</p>
                            <p class="mt-1 text-xs text-gray-500" x-show="! workflowId" x-cloak>Leave blank to resolve automatically from Brand + Document Type on submit; if none matches, you'll be asked to pick one.</p>
                            <x-input-error :messages="$errors->get('workflow_template_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="project_id" value="Project (optional)" />
                            <select id="project_id" name="project_id" x-model="projectId" @change="onProjectChange()" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
                                <option value="">— No project —</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Groups this document with others under the same initiative in the <a href="{{ route('projects.index') }}" class="text-brand-600 hover:underline">Projects</a> dashboard. Can be changed later.</p>
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
                                            <p class="text-sm font-semibold text-gray-800">Approvers for this workflow</p>
                                            <p class="text-xs text-gray-500 mt-0.5">Where a stage has several candidates you can send it to one named person; leave all ticked to use the full group.</p>
                                        </div>
                                        <button type="button" class="shrink-0 text-xs px-2.5 py-1 bg-white border border-brand-300 text-brand-700 rounded-md hover:bg-brand-50" @click="openEditor()">Edit stages…</button>
                                    </div>
                                    <template x-for="stage in templateApprovers[workflowId]" :key="stage.stage_id">
                                        <div class="rounded border border-gray-200 bg-white p-3">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm font-medium text-gray-700">
                                                    <span x-text="stage.sequence_no + '. ' + stage.name"></span>
                                                    <span x-show="stage.parallel_group" class="ml-1 text-[10px] uppercase tracking-wide text-brand-600">parallel</span>
                                                </span>
                                                <span class="text-xs text-gray-400" x-text="stage.candidates.length + (stage.candidates.length === 1 ? ' candidate' : ' candidates')"></span>
                                            </div>
                                            <div class="mt-2 grid grid-cols-2 gap-1.5">
                                                <template x-for="cand in stage.candidates" :key="cand.id">
                                                    <label class="flex items-center gap-2 text-sm text-gray-600">
                                                        <input type="checkbox"
                                                               :name="'stage_approvers[' + stage.stage_id + '][]'"
                                                               :value="cand.id"
                                                               checked
                                                               :disabled="stage.candidates.length === 1"
                                                               class="rounded border-gray-300 text-brand-600">
                                                        <span x-text="cand.name"></span>
                                                    </label>
                                                </template>
                                            </div>
                                            <p x-show="stage.candidates.length === 1" class="mt-1 text-[11px] text-gray-400">Only one candidate — nothing to choose.</p>
                                        </div>
                                    </template>
                                    <x-input-error :messages="$errors->get('stage_approvers')" class="mt-1" />
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
                                                            <select multiple x-model="st.approverUserIds" class="w-full text-xs border-gray-300 rounded-md h-24">
                                                                <template x-for="u in allUsers" :key="u.id">
                                                                    <option :value="String(u.id)" x-text="u.name"></option>
                                                                </template>
                                                            </select>
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

                            <input type="hidden" name="workflow_edit" :value="workflowEditJson">
                        </div>
                    </template>

                    <div class="grid grid-cols-3 gap-4">
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

                    <div>
                        <x-input-label for="file" value="File" />
                        <input type="file" id="file" name="file" required class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200" />
                        <p class="mt-1 text-xs text-gray-500">Any file type accepted, up to 500MB.</p>
                        <x-input-error :messages="$errors->get('file')" class="mt-2" />
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
