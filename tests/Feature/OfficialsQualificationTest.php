<?php

namespace Tests\Feature;

use App\Filament\Resources\CompetitionResource\Pages\EditCompetition;
use App\Models\Competition;
use App\Models\Federation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замечание заказчика (21.10): у Главного судьи и Главного секретаря в
 * настройках турнира — графа «Судейская квалификация»; настройки расположены
 * в две колонки (слева Главный судья, справа Главный секретарь). Квалификация
 * печатается на листе «Состав судейской коллегии» итогового протокола
 * (колонка «Категория»); в подвале с подписями квалификация сознательно
 * не пишется. На титульном листе итогового протокола название соревнования
 * масштабируется по длине, чтобы уместиться в одну строку.
 */
class OfficialsQualificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Как в CompetitionAvatarTest: тесты требуют рабочего драйвера БД;
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

    /** В карточке соревнования есть графы судейской квалификации обоих лиц. */
    public function test_competition_settings_have_qualification_fields(): void
    {
        $competition = $this->makeCompetition();
        $this->actingAs($this->makeUser('quals-form-admin@test.local', 'admin'));

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->assertFormFieldExists('chief_judge_qualification')
            ->assertFormFieldExists('chief_secretary_qualification');
    }

    /** Обе квалификации сохраняются из настроек турнира. */
    public function test_admin_can_save_qualifications_in_competition_settings(): void
    {
        $competition = $this->makeCompetition();
        $this->actingAs($this->makeUser('quals-save-admin@test.local', 'admin'));

        Livewire::test(EditCompetition::class, ['record' => $competition->getRouteKey()])
            ->fillForm([
                'chief_judge_qualification' => 'ССМК',
                'chief_secretary_qualification' => 'ССВК',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $competition->refresh();

        $this->assertSame('ССМК', $competition->chief_judge_qualification);
        $this->assertSame('ССВК', $competition->chief_secretary_qualification);
    }
    /** Лист «Состав судейской коллегии»: квалификация обоих официальных лиц. */
    public function test_judges_sheet_shows_officials_qualifications(): void
    {
        $html = view('pdf.parts.admin-page', $this->adminPageData([
            'chiefJudgeQualification' => 'ССМК',
            'chiefSecretaryQualification' => 'ССВК',
        ]))->render();

        $this->assertMatchesRegularExpression(
            '/<b>Главный судья<\/b>.*?<td>ССМК<\/td>/su',
            $html,
            'Квалификация Главного судьи должна печататься на листе судейской коллегии.',
        );
        $this->assertMatchesRegularExpression(
            '/<b>Главный секретарь<\/b>.*?<td>ССВК<\/td>/su',
            $html,
            'Квалификация Главного секретаря должна печататься на листе судейской коллегии.',
        );
    }

    /** Без квалификации в листе остаётся «—», как раньше. */
    public function test_judges_sheet_keeps_dash_when_qualification_is_empty(): void
    {
        $html = view('pdf.parts.admin-page', $this->adminPageData([
            'chiefJudgeQualification' => null,
            'chiefSecretaryQualification' => null,
        ]))->render();

        $this->assertMatchesRegularExpression(
            '/<b>Главный судья<\/b>.*?<td>—<\/td>/su',
            $html,
            'Без квалификации в колонке «Категория» остаётся прочерк.',
        );
        $this->assertMatchesRegularExpression(
            '/<b>Главный секретарь<\/b>.*?<td>—<\/td>/su',
            $html,
            'Без квалификации в колонке «Категория» остаётся прочерк.',
        );
    }

    /** В подвале итогового протокола (подписи) квалификация не пишется. */
    public function test_protocol_footer_signatures_have_no_qualification(): void
    {
        $html = view('pdf.final-results', $this->finalResultsData([
            'chiefJudgeQualification' => 'ССМК',
            'chiefSecretaryQualification' => 'ССВК',
        ]))->render();

        preg_match('/<footer>(.*?)<\/footer>/su', $html, $matches);
        $footer = $matches[1] ?? '';
        $this->assertNotSame('', $footer, 'В итоговом протоколе должен быть подвал с подписями.');

        $this->assertStringContainsString('Главный судья', $footer);
        $this->assertStringContainsString('Главный секретарь', $footer);
        $this->assertStringContainsString('Иванов И.И.', $footer);
        $this->assertStringContainsString('Петрова А.А.', $footer);
        $this->assertStringNotContainsString('ССМК', $footer, 'В подписях подвала квалификация не пишется.');
        $this->assertStringNotContainsString('ССВК', $footer, 'В подписях подвала квалификация не пишется.');
    }

    /** Подвал титульного листа (подписи) — тоже без квалификации. */
    public function test_title_page_footer_signatures_have_no_qualification(): void
    {
        $html = view('pdf.title-page-standalone', $this->titlePageData([
            'chief_judge_qualification' => 'ССМК',
            'chief_secretary_qualification' => 'ССВК',
        ]))->render();

        preg_match('/<footer>(.*?)<\/footer>/su', $html, $matches);
        $footer = $matches[1] ?? '';
        $this->assertNotSame('', $footer, 'На титульном листе должен быть подвал с подписями.');

        $this->assertStringContainsString('Иванов И.И.', $footer);
        $this->assertStringContainsString('Петрова А.А.', $footer);
        $this->assertStringNotContainsString('ССМК', $footer, 'В подписях подвала квалификация не пишется.');
        $this->assertStringNotContainsString('ССВК', $footer, 'В подписях подвала квалификация не пишется.');
    }
    /** Титул итогового протокола: название масштабируется в одну строку. */
    public function test_title_page_scales_competition_name_to_single_line(): void
    {
        $short = view('pdf.title-page-standalone', $this->titlePageData())->render();

        $this->assertStringContainsString(
            'class="title-comp-name" style="font-size: 20px; white-space: nowrap;"',
            $short,
            'Короткое название печатается базовым кеглем в одну строку.',
        );

        $longName = trim(str_repeat('Чемпионат Федерации Ушу России среди юношей и ветеранов ', 2));
        $long = view('pdf.title-page-standalone', $this->titlePageData(['name' => $longName]))->render();

        preg_match('/title-comp-name" style="font-size: ([0-9.]+)px;/', $long, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'У длинного названия должен задаваться кегль.');
        $this->assertLessThan(20, (float) $matches[1], 'Длинное название масштабируется вниз.');
        $this->assertStringContainsString('white-space: nowrap', $long);

        // Ещё более длинное название — кегль ещё меньше (строка всё ещё одна).
        $veryLong = trim(str_repeat('Чемпионат Федерации Ушу России среди юношей и ветеранов ', 5));
        $very = view('pdf.title-page-standalone', $this->titlePageData(['name' => $veryLong]))->render();

        preg_match('/title-comp-name" style="font-size: ([0-9.]+)px;/', $very, $veryMatches);
        $this->assertNotEmpty($veryMatches[1] ?? null);
        $this->assertLessThan(
            (float) $matches[1],
            (float) $veryMatches[1],
            'Чем длиннее название, тем меньше кегль.',
        );
    }

    /** Smoke: PDF итогового протокола и титульного листа генерируются. */
    public function test_protocol_pdfs_generate_with_qualifications_set(): void
    {
        $competition = $this->makeCompetition([
            'chief_judge_qualification' => 'ССМК',
            'chief_secretary_qualification' => 'ССВК',
        ]);

        $this->get(route('competition.final-results', $competition))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('competition.title-page', $competition))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    private function adminPageData(array $overrides = []): array
    {
        return array_merge([
            'chiefJudgeName' => 'Иванов И.И.',
            'chiefSecretaryName' => 'Петрова А.А.',
            'chiefJudgeQualification' => null,
            'chiefSecretaryQualification' => null,
            'headJudges' => collect(),
            'lineJudges' => collect(),
        ], $overrides);
    }

    private function finalResultsData(array $overrides = []): array
    {
        return array_merge([
            'competition' => $this->makeCompetition(),
            'grouped' => collect(),
            'organizerName' => 'Федерация ушу',
            'formattedDate' => '12 ноября 2026 г.',
            'address' => 'Москва, Дворец спорта «Лужники»',
            'logoBase64' => null,
            'judgeSignBase64' => null,
            'secSignBase64' => null,
            'stampBase64' => null,
            'judgeName' => 'Иванов И.И.',
            'secName' => 'Петрова А.А.',
            'teams' => [],
            'totalAthletes' => 0,
            'totalClubs' => 0,
            'chiefJudgeName' => 'Иванов И.И.',
            'chiefSecretaryName' => 'Петрова А.А.',
            'chiefJudgeQualification' => null,
            'chiefSecretaryQualification' => null,
            'headJudges' => collect(),
            'lineJudges' => collect(),
        ], $overrides);
    }

    private function titlePageData(array $competitionAttributes = []): array
    {
        return [
            'competition' => $this->makeCompetition($competitionAttributes),
            'organizerName' => 'Федерация ушу',
            'formattedDate' => '12 ноября 2026 г.',
            'address' => 'Москва, Дворец спорта «Лужники»',
            'logoBase64' => null,
            'judgeSignBase64' => null,
            'secSignBase64' => null,
            'stampBase64' => null,
            'judgeName' => 'Иванов И.И.',
            'secName' => 'Петрова А.А.',
            'totalAthletes' => 0,
            'totalClubs' => 0,
        ];
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