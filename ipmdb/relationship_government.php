<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_government.php
|--------------------------------------------------------------------------
| DAD / IPMdb Government Program Alignment
|--------------------------------------------------------------------------
*/

final class GovernmentProgramAlignment
{
    private PDO $pdo;
    private string $priority = 'DAD';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function install(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS relationship_government (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_asset_id VARCHAR(120) DEFAULT NULL,
                program_name VARCHAR(255) NOT NULL,
                government_level VARCHAR(80) DEFAULT NULL,
                agency VARCHAR(255) DEFAULT NULL,
                jurisdiction VARCHAR(255) DEFAULT NULL,
                relationship_type VARCHAR(120) DEFAULT 'Government Program Alignment',
                alignment_score DECIMAL(5,2) DEFAULT 0.00,
                confidence_score DECIMAL(5,2) DEFAULT 0.00,
                priority VARCHAR(80) DEFAULT 'DAD',
                funding_eligible TINYINT(1) DEFAULT 0,
                partnership_eligible TINYINT(1) DEFAULT 0,
                referral_eligible TINYINT(1) DEFAULT 0,
                status VARCHAR(40) DEFAULT 'proposed',
                reason TEXT NULL,
                evidence TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    public function create(array $data): int
    {
        $this->install();

        $score = $this->score($data);

        $stmt = $this->pdo->prepare("
            INSERT INTO relationship_government
                (
                    source_asset_id,
                    program_name,
                    government_level,
                    agency,
                    jurisdiction,
                    relationship_type,
                    alignment_score,
                    confidence_score,
                    priority,
                    funding_eligible,
                    partnership_eligible,
                    referral_eligible,
                    status,
                    reason,
                    evidence
                )
            VALUES
                (
                    :source_asset_id,
                    :program_name,
                    :government_level,
                    :agency,
                    :jurisdiction,
                    :relationship_type,
                    :alignment_score,
                    :confidence_score,
                    :priority,
                    :funding_eligible,
                    :partnership_eligible,
                    :referral_eligible,
                    :status,
                    :reason,
                    :evidence
                )
        ");

        $stmt->execute([
            ':source_asset_id' => $data['source_asset_id'] ?? null,
            ':program_name' => $data['program_name'] ?? 'Unnamed Government Program',
            ':government_level' => $data['government_level'] ?? null,
            ':agency' => $data['agency'] ?? null,
            ':jurisdiction' => $data['jurisdiction'] ?? null,
            ':relationship_type' => $data['relationship_type'] ?? 'Government Program Alignment',
            ':alignment_score' => $score['alignment_score'],
            ':confidence_score' => $score['confidence_score'],
            ':priority' => $this->priority,
            ':funding_eligible' => !empty($data['funding_eligible']) ? 1 : 0,
            ':partnership_eligible' => !empty($data['partnership_eligible']) ? 1 : 0,
            ':referral_eligible' => !empty($data['referral_eligible']) ? 1 : 0,
            ':status' => $data['status'] ?? 'proposed',
            ':reason' => $data['reason'] ?? $score['reason'],
            ':evidence' => $data['evidence'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function score(array $data): array
    {
        $text = strtolower(implode(' ', array_map('strval', $data)));

        $score = 20;
        $hits = [];

        $terms = [
            'dad' => 15,
            'dollar a day' => 20,
            'homeless' => 15,
            'homelessness' => 15,
            'housing' => 12,
            'shelter' => 10,
            'detox' => 12,
            'triage' => 12,
            'funding' => 10,
            'grant' => 10,
            'pilot' => 10,
            'outreach' => 8,
            'rent' => 8,
            'arrears' => 8,
            'disbursement' => 12,
            'supportive housing' => 12,
            'mental health' => 8,
            'addiction' => 8,
            'public safety' => 6,
            'encampment' => 10,
            'government' => 6,
            'municipal' => 6,
            'provincial' => 6,
            'federal' => 6,
            'indigenous' => 8,
            'victoria' => 10,
        ];

        foreach ($terms as $term => $value) {
            if (str_contains($text, $term)) {
                $score += $value;
                $hits[] = $term;
            }
        }

        $score = min(100, $score);

        return [
            'alignment_score' => $score,
            'confidence_score' => min(100, $score + 5),
            'reason' => 'Government Program Alignment detected. Matched: ' . implode(', ', array_unique($hits)),
        ];
    }

    public function pending(int $limit = 50): array
    {
        $this->install();

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM relationship_government
            WHERE status = 'proposed'
            ORDER BY alignment_score DESC, created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approve(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE relationship_government
            SET status = 'active'
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    public function archive(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE relationship_government
            SET status = 'archived'
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $id]);
    }
}

function ipmdb_government_alignment(PDO $pdo): GovernmentProgramAlignment
{
    return new GovernmentProgramAlignment($pdo);
}