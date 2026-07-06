<x-dialog-drawer
    x-model="showMergeSignatureDrawer"
    onCloseClick="showMergeSignatureDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.electronic_signature') }}
    </x-slot>

    <div x-data="{
         fileUploaded: false,
         fileName: '',
         password: '',
         selectedKnedp: '',
         get canSign() {
             return this.selectedKnedp && this.fileUploaded && this.password;
         }
     }">
        <div class="space-y-6 max-w-2xl">
         <div>
             <label for="mergeKnedp" class="default-label">{{ __('forms.knedp') }} *</label>
             <select class="input-modal w-full" x-model="selectedKnedp" name="mergeKnedp" id="mergeKnedp">
                 <option value="" selected>{{ __('preperson.merge.select_knedp') }}</option>
                 @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                     <option value="{{ $certificateType['id'] }}" wire:key="merge-{{ $certificateType['id'] }}">
                         {{ $certificateType['name'] }}
                     </option>
                 @endforeach
             </select>
         </div>

         <div>
             <label class="default-label">{{ __('preperson.merge.container_path') }}</label>
             <label for="mergeKeyFile"
                    class="flex flex-col items-center justify-center w-full h-48 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:hover:border-gray-500"
             >
                 <div class="flex flex-col items-center justify-center pt-5 pb-6">
                     <svg class="w-8 h-8 mb-4 text-gray-505 dark:text-gray-400"
                          aria-hidden="true"
                          xmlns="http://www.w3.org/2000/svg"
                          fill="none"
                          viewBox="0 0 20 16"
                     >
                         <path stroke="currentColor"
                               stroke-linecap="round"
                               stroke-linejoin="round"
                               stroke-width="2"
                               d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"
                         />
                     </svg>
                     <p class="mb-2 px-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                         <span class="font-semibold text-blue-600 dark:text-blue-400">{{ __('preperson.merge.drag_key_file') }}</span>
                         {{ __('preperson.merge.or_upload_from_device') }}
                     </p>
                     <p class="px-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                         {{ __('preperson.merge.key_file_extension_hint') }}
                     </p>
                 </div>
                 <input id="mergeKeyFile"
                        type="file"
                        class="hidden"
                        accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                        @change="fileUploaded = true; fileName = $event.target.files[0].name"
                 />
             </label>
             <template x-if="fileUploaded">
                 <div x-transition class="text-sm text-green-700 mt-2 font-medium">
                     {!! __('preperson.merge.file_uploaded_success', ['name' => '<span class="font-semibold" x-text="fileName"></span>']) !!}
                 </div>
             </template>
         </div>

         <div>
             <label for="mergePassword" class="default-label">{{ __('forms.password') }} *</label>
             <input x-model="password"
                    type="password"
                    class="default-input w-full"
                    id="mergePassword"
                    name="mergePassword"
                    autocomplete="current-password"
             />
         </div>

         <div class="flex gap-3 pt-6">
             <button type="button"
                     @click="showMergeSignatureDrawer = false; showMergeFinalConsentDrawer = true"
                     class="button-minor"
             >
                 {{ __('forms.cancel') }}
             </button>

             <button type="button"
                     :disabled="!canSign"
                     @click="completeMerge(currentMethod)"
                     class="button-primary"
             >
                 {{ __('forms.sign') }}
             </button>
         </div>
    </div>
    </div>
</x-dialog-drawer>
