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
                            <select id="workflow_template_id" name="workflow_template_id" x-model="workflowId" @change="suggested = false" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">
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
