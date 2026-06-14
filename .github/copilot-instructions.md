# GitHub Copilot System Instructions

You are assisting on a Laravel 12 application running inside Laravel Sail (Docker) on PHP 8.5.

## Critical Rules & Guidelines

Always read and strictly adhere to the project rules defined in [AGENTS.md](file:///wsl.localhost/Ubuntu/home/mefizz/projects/ohealth/AGENTS.md).

### Environment & Commands
- **Laravel Sail:** This project runs inside Docker. All PHP, Composer, Artisan, and Node commands MUST be prefixed with `vendor/bin/sail` (e.g., `vendor/bin/sail artisan migrate`, `vendor/bin/sail composer install`).
- **Pint Formatter:** Every time you modify PHP code, ensure it matches style requirements by suggesting or running `vendor/bin/sail pint --dirty`.
- **Database Schema:** Before writing migrations or models, check existing table definitions using database tools or configs.

### PHP & Laravel Standards
- Use PHP 8 constructor property promotion: `public function __construct(public Service $service) {}`.
- Specify explicit return type declarations and parameter type hints.
- Always use curly braces for all control structures.
- Use Laravel 10 file structure directories for middleware (`app/Http/Middleware`) and providers (`app/Providers`).

### Testing
- Write PHPUnit tests for all new features.
- Run tests via `vendor/bin/sail artisan test --compact`.
