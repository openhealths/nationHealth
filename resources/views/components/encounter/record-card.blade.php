@props(['record', 'sectionId'])

<div class="record-inner-card overflow-hidden !rounded-[18px] !border-gray-300 dark:!border-gray-600">
    <div class="record-inner-header !flex-row">
        <div class="record-inner-checkbox-col !w-11 !border-r !border-b-0 !px-3 !py-3">
            <input
                type="checkbox"
                class="default-checkbox h-4 w-4"
                aria-label="{{ __('forms.select') }}: {{ $record['title'] }}"
                id="{{ $sectionId }}-record-{{ $record['id'] }}"
            />
        </div>
        <div class="record-inner-column min-w-0 flex-1 !px-3 !py-3">
            <div class="record-inner-label !mb-1 !text-[9px] !text-gray-900 dark:!text-gray-300">
                {{ $record['subtitle'] }}
            </div>
            <div class="record-inner-value break-words !font-semibold">
                {{ $record['title'] }}
            </div>
        </div>
        <div class="record-inner-action-col !w-12 !border-t-0 !border-l !p-3">
            <button
                type="button"
                class="record-inner-action-btn rounded-md p-1 hover:bg-gray-100 dark:hover:bg-gray-700"
                aria-label="{{ __('forms.actions') }}: {{ $record['title'] }}"
            >
                @icon('edit-user-outline', 'svg-hover-action w-5 h-5')
            </button>
        </div>
    </div>
    <div class="record-inner-body">
        <div class="record-inner-grid-container gap-4 !px-3 !py-4 md:!pl-3">
            @foreach ($record['detailRows'] as $detailRow)
                <dl class="grid gap-x-5 gap-y-4 {{ $detailRow['gridClass'] }}">
                    @foreach ($detailRow['fields'] as $detail)
                        <div class="min-w-0 {{ $detail['class'] ?? '' }}">
                            <dt @class([
                                'record-inner-label !mb-1.5 !text-[9px] !text-gray-900 dark:!text-gray-300',
                                'uppercase' => $record['uppercaseLabels'] ?? true,
                            ])>
                                {{ $detail['label'] }}
                            </dt>
                            <dd class="record-inner-value break-words !text-xs !font-normal">
                                {{ $detail['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endforeach
        </div>
    </div>
</div>
