<?php
declare(strict_types=1);

function ensure_public_officers_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS officer_profiles (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        position VARCHAR(100) DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Everyone shown publicly as JPCS leadership: officer/adviser accounts plus public officer profiles,
 * grouped as adviser (the system's admin account), president, then officers (vice presidents first).
 * @return array{adviser: list<array>, president: list<array>, officer: list<array>}
 */
function jpcs_officer_tiers(mysqli $conn): array
{
    ensure_public_officers_table($conn);
    $sources = [
        "SELECT name, position, photo, role FROM users WHERE role IN ('officer', 'admin_advisor', 'admin_adviser') ORDER BY id ASC",
        "SELECT name, position, photo, 'officer' AS role FROM officer_profiles ORDER BY id ASC",
    ];
    $tiers = ['adviser' => [], 'president' => [], 'officer' => []];
    foreach ($sources as $sql) {
        foreach ($conn->query($sql)->fetch_all(MYSQLI_ASSOC) as $row) {
            $initials = '';
            foreach (preg_split('/\s+/', trim((string) $row['name'])) ?: [] as $part) {
                if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            $position = trim((string) $row['position']);
            $lower = mb_strtolower($position);
            $isPresident = str_contains($lower, 'president') && !str_contains($lower, 'vice');
            $tier = $row['role'] !== 'officer' ? 'adviser' : ($isPresident ? 'president' : 'officer');
            $tiers[$tier][] = [
                'name' => (string) $row['name'],
                'position' => $position,
                'photo' => trim((string) $row['photo']),
                'group' => $tier === 'adviser' ? 'adviser' : 'officer',
                'tier' => $tier,
                'rank' => $tier === 'officer' && str_contains($lower, 'vice') ? 0 : 1,
                'initials' => mb_substr($initials !== '' ? $initials : 'J', 0, 2),
            ];
        }
    }
    usort($tiers['officer'], static fn($a, $b) => $a['rank'] <=> $b['rank']);
    return $tiers;
}

const JPCS_OFFICER_TIER_LABELS = ['adviser' => 'Adviser', 'president' => 'President', 'officer' => 'Officers'];
