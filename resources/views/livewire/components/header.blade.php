<nav
    class="bg-white border-b border-gray-200 px-4 py-2.5 dark:bg-gray-800 dark:border-gray-700 fixed left-0 right-0 top-0 z-50">
    <div class="flex flex-wrap justify-between items-center max-lg:flex-nowrap max-lg:gap-2">
        <div class="flex max-lg:min-w-0 justify-start items-center">

            <button data-drawer-target="drawer-navigation"
                    data-drawer-toggle="drawer-navigation"
                    aria-controls="drawer-navigation"
                    class="p-2 mr-2 max-lg:shrink-0 text-gray-600 rounded-lg cursor-pointer lg:hidden hover:text-gray-900 hover:bg-gray-100 focus:bg-gray-100 dark:focus:bg-gray-700 focus:ring-2 focus:ring-gray-100 dark:focus:ring-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
            >
                <svg
                    aria-hidden="true"
                    class="w-6 h-6"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        fill-rule="evenodd"
                        d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"
                        clip-rule="evenodd"
                    ></path>
                </svg>
                <svg
                    aria-hidden="true"
                    class="hidden w-6 h-6"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        fill-rule="evenodd"
                        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                        clip-rule="evenodd"
                    ></path>
                </svg>
                <span class="sr-only">Toggle sidebar</span>
            </button>

            {{-- Logo --}}
            <a href="{{ legalEntity() ? route('dashboard', [legalEntity()]) : url('/dashboard') }}" class="flex max-lg:min-w-0 items-center justify-between mr-4 max-sm:mr-0">
                <img src="{{ Vite::asset('resources/images/logo-180x180.png') }}"
                     class="mr-3 h-8 max-lg:shrink-0 max-sm:mr-0"
                     alt="Nation Health logo"
                >
                <span class="max-sm:hidden self-center text-l font-bold whitespace-nowrap dark:text-white text-teal uppercase">
                    Nation Health
                </span>
            </a>
        </div>

        <div class="flex max-lg:shrink-0 items-center lg:order-2">
            {{-- Change theme color --}}
            <button
                x-data="{
                    darkMode: false,
                    init() {
                        // Sync with DOM state set by inline script in <head>
                        this.darkMode = document.documentElement.classList.contains('dark');
                    },
                    toggleTheme() {
                        this.darkMode = !this.darkMode;
                        localStorage.setItem('color-theme', this.darkMode ? 'dark' : 'light');
                        if (this.darkMode) {
                            document.documentElement.classList.add('dark');
                        } else {
                            document.documentElement.classList.remove('dark');
                        }
                    }
                }"
                @click="toggleTheme()"
                type="button"
                aria-label="Toggle theme color"
                class="cursor-pointer p-2 mr-1 text-gray-500 rounded-lg hover:text-gray-900 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-white dark:hover:bg-gray-700 focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600"
            >

                <span x-show="!darkMode" class="dark:hidden">
                    @icon('moon', 'w-6 h-6')
                </span>

                <span x-show="darkMode" class="hidden dark:block">
                    @icon('sun', 'w-6 h-6')
                </span>
            </button>

            {{-- Notifications --}}
            @livewire('notifications-dropdown')

            {{-- Profile dropdown menu --}}
            <div class="relative"
                 x-data="{ profileMenuOpen: false }"
                 @click.outside="profileMenuOpen = false"
                 @keydown.escape.prevent.stop="profileMenuOpen = false; $refs.profileMenuButton.focus()"
            >
                <button type="button"
                        class="cursor-pointer flex mx-3 text-sm text-gray-500 rounded-full md:mr-0 focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600"
                        id="user-menu-button"
                        x-ref="profileMenuButton"
                        aria-expanded="false"
                        :aria-expanded="profileMenuOpen.toString()"
                        aria-controls="user-profile-menu"
                        @click="profileMenuOpen = !profileMenuOpen"
                >
                    <span class="sr-only">Open user menu</span>

                    {{-- TODO if user has a profile picture add it here --}}
                    <svg class="w-8 h-8"
                         aria-hidden="true"
                         xmlns="http://www.w3.org/2000/svg"
                         width="24" height="24"
                         fill="currentColor"
                         viewBox="0 0 24 24"
                    >
                        <path fill-rule="evenodd"
                              d="M12 20a7.966 7.966 0 0 1-5.002-1.756l.002.001v-.683c0-1.794 1.492-3.25 3.333-3.25h3.334c1.84 0 3.333 1.456 3.333 3.25v.683A7.966 7.966 0 0 1 12 20ZM2 12C2 6.477 6.477 2 12 2s10 4.477 10 10c0 5.5-4.44 9.963-9.932 10h-.138C6.438 21.962 2 17.5 2 12Zm10-5c-1.84 0-3.333 1.455-3.333 3.25S10.159 13.5 12 13.5c1.84 0 3.333-1.455 3.333-3.25S13.841 7 12 7Z"
                              clip-rule="evenodd"
                        />
                    </svg>
                </button>

                <div id="user-profile-menu"
                     x-cloak
                     x-show="profileMenuOpen"
                     class="absolute right-0 top-full z-50 mt-2 w-56 max-w-[calc(100vw-2rem)] text-base list-none bg-white divide-y divide-gray-100 shadow dark:bg-gray-700 dark:divide-gray-600 rounded-xl"
                >
                    <div class="py-3 px-4">
                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">
                            {{-- TODO: Get and show the name of the current user --}}
                        </span>
                        <span class="block text-sm text-gray-900 truncate dark:text-white">
                            {{ auth()->user()->email }}
                        </span>
                    </div>

                    <ul class="py-1 text-gray-700 dark:text-gray-300" aria-labelledby="user-menu-button">
                        <li>
                            <a href="{{ route('profile.show') }}"
                               class="block py-2 px-4 text-sm hover:bg-gray-100 dark:hover:bg-gray-600 dark:text-gray-400 dark:hover:text-white"
                            >
                                {{ __('general.profile') }}
                            </a>
                        </li>

                        <li>
                            <form method="POST" action="{{ route('logout') }}" x-data>
                                @csrf

                                <x-dropdown-link
                                    href="{{ route('logout') }}"
                                    @click.prevent="$root.submit();"
                                >
                                    {{ __('Вийти') }}
                                </x-dropdown-link>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
