<?php

namespace Tests\Feature;

use App\Filament\Resources\CompetitionResource\Pages\EditCompetition;
use App\Filament\Resources\CompetitionResource\Pages\ListCompetitions;
use App\Filament\Widgets\CurrentCompetitionWidget;
use App\Models\Competition;
use App\Models\Federation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (05.10): аватар турнира (competitions.avatar_path) —
 * картинка самого турнира, которую админ загружает в настройках соревнования.
 * Показывается только на виджете «Актуальное соревнование» (там логотип
 * федерации убран — аватар единственная картинка слева, 80×80, круглая).
 * С публичной страницы результатов и с табло аватар убран — там остаётся
 * только логотип федерации. Без загруженного аватара элемент не рисуется
 * вовсе. В документы (протоколы, дипломы) аватар сознательно не входит.
 */
class CompetitionAvatarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в UserAvatarTest: тесты требуют рабочего драйвера БД;
     * если нужного PDO-драйвера нет — пропускаем, а не падаем.
     */
    protected function setUp(): void
    {
        // Проверяем до parent::setUp(), поэтому config() ещё недоступен.
        $connection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? 'sqlite';

        $driver = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'sqlsrv' => 'sqlsrv',
            default => 'sqlite',
        };

        if (! in_array($driver, \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("PDO-драйвер «{$driver}» не установлен (нужен для тестовой БД).");
        }

        parent::setUp();
    }

    /** Модель отдаёт URL загруженного аватара; без файла — null. */
    public function test_competition_model_exposes_uploaded_avatar_url(): void
    {
        $competition = $this->makeCompetition();

        $this->assertNull($competition->avatarUrl());

        $competition->avatar_path = 'competitions/avatars/cup.png';

        $this->assertSame(
            asset('storage/competitions/avatars/cup.png'),
            $competition->avatarUrl(),
        );
    }

    /** Админ загружает аватар в настройках соревнования: файл сохраняется, путь пишется в БД. */
    public function test_admin_can_upload_avatar_in_competition_settings(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('comp-avatar-admin@test.local', 'admin');
        $competition = $this->makeCompetition();

        $this->actingAs($admin);

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertFormFieldExists('avatar_path')
            ->fillForm([
                'avatar_path' => UploadedFile::fake()->image('cup.png'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $competition->refresh();

        $this->assertNotNull($competition->avatar_path);
        // Замечание заказчика (05.10): файлы аватаров турниров живут в
        // competitions/avatars (не в avatars, как у пользователей).
        $this->assertStringStartsWith('competitions/avatars/', $competition->avatar_path);
        Storage::disk('public')->assertExists($competition->avatar_path);
    }

    /**
     * Замечание заказчика (05.10): в списке соревнований колонка «Организатор»
     * убрана; первой колонкой идёт аватар турнира из настроек соревнования.
     */
    public function test_competitions_table_shows_avatar_column_first_without_organizer(): void
    {
        $this->makeCompetition([
            'avatar_path' => 'competitions/avatars/cup.png',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $table = Livewire::actingAs($this->makeUser('comp-avatar-table@test.local', 'admin'))
            ->test(ListCompetitions::class)
            ->assertTableColumnExists('avatar_path')
            ->assertTableColumnDoesNotExist('federation.name');

        $columns = $table->instance()->getTable()->getColumns();

        $this->assertSame(
            'avatar_path',
            array_key_first($columns),
            'Аватар турнира — первая колонка списка соревнований (замечание заказчика 05.10).',
        );
    }

    /**
     * На виджете «Актуальное соревнование» логотип федерации не показывается
     * (замечание заказчика 05.10) — аватар единственная картинка слева
     * (80×80, круглая).
     */
    public function test_widget_shows_avatar_without_federation_logo(): void
    {
        $this->makeCompetition([
            'organization_logo' => 'competitions/logos/fed.png',
            'avatar_path' => 'competitions/avatars/cup.png',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::actingAs($this->makeUser('comp-avatar-widget@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->html();

        $this->assertStringNotContainsString('competitions/logos/fed.png', $html, 'Логотип федерации с плашки убран (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('federations/logo.png', $html, 'Логотип федерации с плашки убран (замечание заказчика 05.10).');
        // Размер 80×80 (как раньше у логотипа); форма — круглая.
        $this->assertAvatarTagMatches($html, 'competitions/avatars/cup.png', 'width: 80px; height: 80px; object-fit: contain; border-radius: 50%');
    }

    /** Без загруженного аватара на виджете элемент не рисуется вовсе. */
    public function test_widget_omits_avatar_without_file(): void
    {
        $this->makeCompetition([
            'organization_logo' => 'competitions/logos/fed.png',
            'avatar_path' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($this->makeUser('comp-avatar-widget-none@test.local', 'admin'))
            ->test(CurrentCompetitionWidget::class)
            ->assertDontSee('Аватар турнира')
            ->assertDontSee('competitions/avatars')
            // Логотип федерации на плашке не показывается ни при каких настройках.
            ->assertDontSee('competitions/logos/fed.png')
            ->assertDontSee('federations/logo.png');
    }

    /**
     * Замечание заказчика (05.10): с публичной страницы результатов аватар
     * турнира убран — остаётся только логотип федерации (даже если аватар
     * загружен).
     */
    public function test_public_results_show_federation_logo_only(): void
    {
        $this->makeCompetition([
            'avatar_path' => 'competitions/avatars/cup.png',
        ]);

        $html = $this->get('/results')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('federations/logo.png', $html, 'Логотип федерации должен остаться на странице результатов.');
        $this->assertStringNotContainsString('competitions/avatars', $html, 'Аватар турнира с публичной страницы результатов убран (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('Аватар турнира', $html);
    }

    /**
     * Замечание заказчика (05.10): с табло аватар турнира убран — остаётся
     * только логотип федерации (даже если аватар загружен).
     */
    public function test_scoreboard_show_federation_logo_only(): void
    {
        $this->makeCompetition([
            'avatar_path' => 'competitions/avatars/cup.png',
        ]);

        $html = $this->get('/scoreboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('federations/logo.png', $html, 'Логотип федерации должен остаться на табло.');
        $this->assertStringNotContainsString('competitions/avatars', $html, 'Аватар турнира с табло убран (замечание заказчика 05.10).');
        $this->assertStringNotContainsString('Аватар турнира', $html);
    }

    /**
     * Guard: аватар сознательно исключён из PDF — в PDF-шаблонах и
     * PDF-контроллерах нет ни одного упоминания аватара.
     */
    public function test_avatar_is_never_used_in_pdf_documents(): void
    {
        $files = array_merge(
            glob(resource_path('views/pdf/*.blade.php')) ?: [],
            glob(resource_path('views/pdf/parts/*.blade.php')) ?: [],
            glob(app_path('Http/Controllers/*Pdf*.php')) ?: [],
            // Дипломы печатает ExportController (export.diplomas).
            glob(app_path('Http/Controllers/ExportController.php')) ?: [],
        );

        $this->assertNotEmpty($files, 'Не найдены PDF-шаблоны/контроллеры для guard-проверки.');

        foreach ($files as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'avatar',
                (string) file_get_contents($file),
                "Файл «{$file}»: аватар турнира сознательно не должен попадать в PDF-документы.",
            );
        }
    }

    /** Smoke: титульный лист PDF генерируется и при заданном аватаре (всё равно его не включая). */
    public function test_title_page_pdf_generates_with_avatar_set(): void
    {
        $competition = $this->makeCompetition([
            'avatar_path' => 'competitions/avatars/cup.png',
        ]);

        $this->get(route('competition.title-page', $competition))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** Тег аватара: ожидаемый размер/стиль и круглая форма (маркер в $sizeMarker). */
    private function assertAvatarTagMatches(string $html, string $avatarSrc, string $sizeMarker): void
    {
        $this->assertMatchesRegularExpression(
            '/<img\b[^>]*'.preg_quote($avatarSrc, '/').'[^>]*'.preg_quote($sizeMarker, '/').'[^>]*>/u',
            $html,
            "Тег аватара «{$avatarSrc}» должен использовать ожидаемый размер/стиль («{$sizeMarker}»).",
        );
    }

    private function makeCompetition(array $attributes = []): Competition
    {
        $federation = Federation::create([
            'name' => 'Федерация ушу '.str()->random(5),
            'logo_path' => 'federations/logo.png',
        ]);

        return Competition::create(array_merge([
            'federation_id' => $federation->id,
            'name' => 'Кубок Москвы 2026',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-15',
            'city' => 'Москва',
            'address' => 'Дворец спорта «Лужники»',
            'chief_judge_name' => 'Иванов И.И.',
            'chief_secretary_name' => 'Петрова А.А.',
            'max_events' => 2,
            'judging_scheme' => Competition::SCHEME_SIMPLE,
            'status_code' => 0,
        ], $attributes));
    }

    private function makeUser(string $email, string $role, array $flags = []): User
    {
        return User::create(array_merge([
            'name' => 'Тестовый пользователь',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active_judge' => $role !== 'admin',
        ], $flags));
    }
}
