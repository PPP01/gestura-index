<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Enum\Category;
use App\Enum\EntryType;
use App\Service\PayloadAnalyzer;
use App\Tests\Functional\ApiTestCase;

abstract class AdminTestCase extends ApiTestCase
{
    protected function createAdmin(string $email = 'chef@example.com', AdminRole $role = AdminRole::Admin, AdminUserStatus $status = AdminUserStatus::Active): AdminUser
    {
        $u = new AdminUser('Chef', $email, $role);
        $u->status = $status;
        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }

    /** @return array<string,string> */
    protected function hdr(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }

    /**
     * Legt $count Credentials für $u an und loggt per Fake-WebAuthn-Ceremony
     * mit der ersten davon ein. $count=2 (Default) sorgt dafür, dass auch
     * Backup-Gate-geschützte Aktionen (reject/ban) in Tests nicht an der
     * »mindestens zwei Passkeys«-Regel scheitern.
     */
    protected function loginWithCredentials(AdminUser $u, int $count = 2): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->em->persist(new WebAuthnCredential($u, "cred-{$u->id}-{$i}", '{"id":"x"}', "Key {$i}"));
        }
        $this->em->flush();

        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => "cred-{$u->id}-1"], JSON_THROW_ON_ERROR));
    }

    /**
     * Legt einen pending Entry mit pending EntryVersion an (Statusmaschinen-
     * Invariante: pending ⇒ currentVersion === null). Analog zu
     * ModerationCommandsTest::createPendingEntry.
     */
    protected function createPendingEntry(string $formatId = 'com.example.pending'): Entry
    {
        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $entry->setCategories([Category::Other]);
        $payload = [
            'gesturaMenu' => 1,
            'id' => $formatId,
            'version' => '1.0.0',
            'name' => 'Neu',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']],
        ];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));

        $this->em->persist($submitter);
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }
}
