# Admin-Backend (SP4a) — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die serverseitige Grundlage für ein Web-Admin-Panel: WebAuthn-/Passkey-Login, Rollen (`admin`/`moderator`), eine `/api/admin/*`-JSON-API über den vorhandenen `ModerationService`, Einladungs-/Registrierungs-Flow per E-Mail, Step-up vor destruktiven Aktionen, Backup-Passkey-Pflicht (≥ 2) und ein unveränderliches Audit-Log — vollständig über Funktionstests prüfbar, ohne dass die SPA (SP4b) existiert.

**Architecture (Hybrid, festgezurrt):** WebAuthn-Ceremonies laufen **manuell** über die `web-auth/webauthn-lib`-Services (Options-Factories, Attestation-/Assertion-Validatoren, Serializer) in **dünnen Invokable-Controllern** — konsistent mit dem SP1-Stil. Das Symfony-Security-Bundle liefert **nur** das Rollen-Gating: eine Firewall auf `^/api/admin` mit einem **eigenen Session-Authenticator**, der unser httpOnly-Session-Cookie in ein Symfony-Token übersetzt, sodass `#[IsGranted]` / `access_control` greifen. Kein Bundle-Firewall-Authenticator, kein TOTP. Session hält User-ID + Zeitstempel der letzten Passkey-Verifikation (für Step-up-Frische).

**Tech Stack:** PHP 8.5 / Symfony 7.4 LTS, Doctrine ORM 3, MariaDB 10.11 (lokal) / MySQL (Server), `web-auth/webauthn-symfony-bundle` ^5.3, `symfony/security-bundle` 7.4.*, `symfony/mailer` 7.4.*, PHPUnit 13 + dama/doctrine-test-bundle.

**Spec:** `docs/superpowers/specs/2026-07-21-admin-backend-design.md` — bei Detailfragen gilt der Spec.

## Global Constraints

- Alle Befehle laufen aus dem Repo-Root (`/home/patric/apache/projekte/gestura-index`); PHP lokal `php` (8.5.3), Composer `composer --working-dir=backend`. Auf dem Server heißt die CLI `php85`.
- Alle Admin-Endpunkte unter `/api/admin`; Fehler immer `application/problem+json` (bestehender `ProblemJsonSubscriber` greift für alle `/api/`-Pfade). Lese-Endpunkte der öffentlichen API und das öffentliche Frontend bleiben **unverändert** (Nicht-Ziel).
- **Auth-Modell:** WebAuthn mit User-Verification `required`; RP-ID `gestura.eu`. Session-Cookie httpOnly, `SameSite=Strict`, `Secure`, `Domain=.gestura.eu` (Server) bzw. leer (lokal), Idle-TTL 30 min (1800 s).
- **Step-up:** destruktive Aktionen brauchen eine frische Passkey-Verifikation < 5 min (300 s), serverseitig geprüft.
- **Backup-Passkey-Gate:** destruktive Aktionen werden mit **409** abgelehnt, solange der handelnde Account **< 2** `WebAuthnCredential` hat. Ein Passkey lässt sich nie unter 2 entfernen (409).
- **Rollen:** `admin` (Moderation + Nutzerverwaltung + Ban + Audit), `moderator` (nur Moderation). `role_hierarchy: ROLE_ADMIN: [ROLE_MODERATOR]`.
- **Entities:** öffentliche typisierte Properties, Auto-Increment-Integer-IDs, Attribut-Mapping, `createdAt` im Konstruktor, Enums als backed string enum ohne Methoden (Mapping an den Aufrufstellen). Services `final class` mit `private readonly` Constructor-Injection.
- **Controller:** `final class` mit `__invoke`, Action-Injection der Dependencies, `#[Route('/api/admin/...', methods: [...])]`, JSON via `json_decode($request->getContent(), true, N, JSON_THROW_ON_ERROR)` in `try/catch (\JsonException)`, Fehler via `throw new ApiProblem(status, title, extra?, headers?)`.
- **Tokens (Einladung):** Format `gsta_<selector 16 hex>_<verifier 43 base64url>`; Verifier nur als Argon2id-Hash gespeichert (`password_hash(..., PASSWORD_ARGON2ID)` ohne explizite Cost-Optionen), Verifikation via `password_verify`. Klartext-Token nie gespeichert.
- **Rate-Limiter:** neue Limiter `admin_login`, `admin_register`, `admin_invite` in `config/packages/rate_limiter.yaml` **und** im `when@test`-Block (großzügige 1000er-Limits); isolierte Drosselungstests mit `InMemoryStorage`. Injektion per Parametername (`$adminLoginLimiter` usw.).
- **CORS:** `/api/admin` bekommt credentialed CORS (feste Origin `https://gestura.eu`, `Access-Control-Allow-Credentials: true`); die öffentliche API behält `*` ohne Credentials. Getrennt im `CorsSubscriber` per Pfad-Präfix.
- **CSRF:** zustandsändernde `/api/admin`-Requests (POST/PATCH/DELETE) müssen den Header `X-Requested-With: XMLHttpRequest` mitschicken (Cross-Site-Formulare können das nicht), sonst 403.
- **Tests:** `php backend/bin/phpunit; echo $?` muss **OK + Exit-Code 0** liefern (`failOnDeprecation="true"`). WebAuthn-Ceremonies in Tests **gemockt** (kein echter Authenticator) — siehe Task 6 (Test-Fake für den Ceremony-Service). Distinkte `REMOTE_ADDR` pro limiter-relevantem Request. `ApiTestCase::setUp()` leert bereits `cache.rate_limiter`.
- **Commits:** Deutsch, Imperativ, Subject ≤ 50 Zeichen, Body mit Warum (72 Zeichen breit), Guillemets »…«, Halbgeviertstrich –, Abschlusszeile `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## Datei-Struktur (Gesamtübersicht)

```text
backend/config/packages/security.yaml        Task 5  (Firewall + Provider + access_control)
backend/config/packages/webauthn.yaml        Task 6  (RP-ID/-Name, Profile, Repositories)
backend/config/packages/framework.yaml       Task 5  (Session-Cookie-Attribute)   [modify]
backend/config/packages/rate_limiter.yaml     Task 12 (admin_login/register/invite) [modify]
backend/config/packages/mailer.yaml           Task 10 (MAILER_DSN)
backend/config/bundles.php                    Task 1  (Security-, WebAuthn-, Mailer-Bundle) [modify]
backend/src/Enum/AdminRole.php                Task 2
backend/src/Enum/AdminUserStatus.php          Task 2
backend/src/Entity/AdminUser.php              Task 3
backend/src/Entity/WebAuthnCredential.php     Task 3
backend/src/Entity/AdminInvite.php            Task 3
backend/src/Entity/AuditLogEntry.php          Task 3
backend/src/Repository/AdminUserRepository.php        Task 3
backend/src/Repository/WebAuthnCredentialRepository.php Task 3
backend/src/Repository/AdminInviteRepository.php      Task 3
backend/src/Repository/AuditLogEntryRepository.php    Task 3
backend/migrations/Version<ts>.php            Task 3
backend/src/Service/InviteTokenService.php    Task 4  (+ GeneratedInvite)
backend/src/Service/AuditLogger.php           Task 4
backend/src/Service/AdminSession.php          Task 5
backend/src/Security/AdminSessionAuthenticator.php    Task 5
backend/src/Security/StepUpGuard.php          Task 7
backend/src/Security/BackupPasskeyGate.php    Task 8
backend/src/Service/WebAuthn/*                Task 6  (Ceremony-Wrapper + Repository-Adapter)
backend/src/Controller/Admin/*                Tasks 7–11
backend/src/Command/AdminCreateCommand.php    Task 13
backend/src/EventSubscriber/CorsSubscriber.php        Task 12 [modify]
backend/src/EventSubscriber/AdminCsrfSubscriber.php   Task 12
backend/tests/Functional/Admin/*             Tasks 5–13
```

## Task-übergreifende Interfaces (verbindlich — überall exakt so benutzen)

```php
// Enums (Task 2)
enum AdminRole: string { case Admin = 'admin'; case Moderator = 'moderator'; }
enum AdminUserStatus: string { case Invited='invited'; case Active='active'; case Disabled='disabled'; }

// AdminUser (Task 3), implements Symfony UserInterface
AdminUser::getRoles(): array          // Admin => ['ROLE_ADMIN'], Moderator => ['ROLE_MODERATOR']
AdminUser::getUserIdentifier(): string // == $this->email
AdminUser::credentialCount(): int      // count($this->credentials)

// InviteTokenService (Task 4) — spiegelt EditTokenService, Prefix gsta_
InviteTokenService::generate(): GeneratedInvite            // ->token, ->selector, ->hash
InviteTokenService::parse(string $token): ?array           // ['selector'=>..,'verifier'=>..] | null
InviteTokenService::verify(string $verifier, string $hash): bool

// AuditLogger (Task 4)
AuditLogger::log(?AdminUser $actor, string $action, ?string $targetType=null, ?string $targetId=null, ?array $detail=null): void

// AdminSession (Task 5) — Wrapper um die Symfony-Session (RequestStack)
AdminSession::login(AdminUser $u): void         // speichert userId + verifiedAt=time()
AdminSession::currentUserId(): ?int
AdminSession::markVerified(): void              // verifiedAt = time()
AdminSession::isFresh(int $maxAgeSeconds): bool // time() - verifiedAt <= maxAge
AdminSession::logout(): void
AdminSession::putChallenge(string $key, string $challengeB64): void
AdminSession::takeChallenge(string $key): ?string  // liest + löscht (one-shot)

// StepUpGuard (Task 7)
StepUpGuard::assertFresh(): void   // wirft ApiProblem(403,'Step-up required',['stepUpRequired'=>true]) wenn !isFresh(300)

// BackupPasskeyGate (Task 8)
BackupPasskeyGate::assertEnough(AdminUser $u): void  // wirft ApiProblem(409,'Backup passkey required',['backupRequired'=>true]) wenn credentialCount()<2

// WebAuthnCeremony (Task 6)
WebAuthnCeremony::creationOptionsJson(AdminUser $u): string   // baut Options, legt Challenge in Session, gibt Client-JSON zurück
WebAuthnCeremony::verifyRegistration(AdminUser $u, string $clientJson, string $label): WebAuthnCredential // Attestation prüfen, Credential anlegen+persistieren
WebAuthnCeremony::requestOptionsJson(?AdminUser $u): string   // Assertion-Options (u=null => discoverable/usernameless Login)
WebAuthnCeremony::verifyAssertion(string $clientJson): AdminUser // Assertion prüfen, Counter/lastUsedAt updaten, zugehörigen AdminUser liefern
```

---

### Task 1: Abhängigkeiten + Bundle-Registrierung + API-Verifikation

Fügt die drei fehlenden Bundles hinzu und stellt sicher, dass die Suite weiter grün bleibt, **bevor** irgendein Feature-Code entsteht. Verifiziert außerdem die exakte, installierte WebAuthn-5.x-API (versions-empfindlich).

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock` (via composer require)
- Modify: `backend/config/bundles.php`

**Interfaces:**
- Produces: geladene Bundles `SecurityBundle`, `Webauthn\Bundle\WebauthnBundle`, `MailerBundle`; autowirebare Services `Webauthn\AuthenticatorAttestationResponseValidator`, `Webauthn\AuthenticatorAssertionResponseValidator`, ein WebAuthn-Serializer, die Options-Factories.

- [ ] **Step 1: Bundles installieren**

```bash
composer --working-dir=backend require symfony/security-bundle:7.4.* symfony/mailer:7.4.* web-auth/webauthn-symfony-bundle:^5.3
```
Erwartung: Installation ok. Falls Flex ein `security.yaml`-Rezept anlegt, wird es in Task 5 vollständig überschrieben; ein evtl. angelegtes `webauthn.yaml` in Task 6.

- [ ] **Step 2: Bundle-Registrierung prüfen/ergänzen**

`backend/config/bundles.php` muss diese Einträge enthalten (Flex trägt sie i. d. R. automatisch ein — sonst manuell ergänzen):
```php
Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
Symfony\Bundle\MailerBundle\MailerBundle::class => ['all' => true],  // ggf. via symfony/mailer-flex
Webauthn\Bundle\WebauthnBundle::class => ['all' => true],
```

- [ ] **Step 3: Installierte WebAuthn-API verifizieren (einmaliger Abgleich gegen vendor)**

Die exakten Service-/Klassennamen sind versions-abhängig. Vor Task 6 einmal bestätigen (nur lesen, kein Code):
```bash
ls backend/vendor/web-auth/webauthn-lib/src | grep -E 'CredentialRecord|AuthenticatorAss|AuthenticatorAtt'
grep -rl "class WebauthnSerializerFactory" backend/vendor/web-auth/
php -r 'require "backend/vendor/autoload.php"; echo class_exists(Webauthn\CredentialRecord::class)?"CredentialRecord ok\n":"FEHLT\n";'
```
Erwartung: `CredentialRecord`, `AuthenticatorAssertionResponseValidator`, `AuthenticatorAttestationResponseValidator` existieren; `WebauthnSerializerFactory` vorhanden. Falls `CredentialRecord` fehlt (ältere 5.x), stattdessen `Webauthn\PublicKeyCredentialSource` verwenden — dann in Task 6 die Typen entsprechend anpassen (gleiche Methoden, alter Name).

- [ ] **Step 4: Suite grün halten (kein Regressionsschaden durch neue Bundles)**

Ein leeres `security.yaml` (nur bis Task 5) verhindert, dass das Security-Bundle mangels Konfiguration failt. Lege temporär an, falls die Suite sonst rot wird:
```yaml
# backend/config/packages/security.yaml (temporär; Task 5 ersetzt es vollständig)
security:
    providers:
        in_memory: { memory: null }
    firewalls:
        main: { security: false }
```

- [ ] **Step 5: Test-Lauf**

Run: `php backend/bin/phpunit; echo $?`
Expected: `OK`, Exit-Code `0`.

- [ ] **Step 6: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/config/bundles.php backend/config/packages/security.yaml
git commit -m "Ergänze Security-, WebAuthn- und Mailer-Bundle"
```

---

### Task 2: Enums `AdminRole` und `AdminUserStatus`

**Files:**
- Create: `backend/src/Enum/AdminRole.php`, `backend/src/Enum/AdminUserStatus.php`

**Interfaces:**
- Produces: `AdminRole::Admin|Moderator`, `AdminUserStatus::Invited|Active|Disabled` (backed string enums, keine Methoden — Rollen-Mapping macht `AdminUser::getRoles()`).

- [ ] **Step 1: Enums schreiben** (kein separater Test — method-freie Wertlisten; Absicherung erfolgt über `AdminUser` in Task 3)

```php
<?php
declare(strict_types=1);
namespace App\Enum;

enum AdminRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
}
```
```php
<?php
declare(strict_types=1);
namespace App\Enum;

enum AdminUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/src/Enum/AdminRole.php backend/src/Enum/AdminUserStatus.php
git commit -m "Ergänze Admin-Rollen- und Status-Enum"
```

---

### Task 3: Entities, Repositories, Migration

**Files:**
- Create: `backend/src/Entity/{AdminUser,WebAuthnCredential,AdminInvite,AuditLogEntry}.php`
- Create: `backend/src/Repository/{AdminUserRepository,WebAuthnCredentialRepository,AdminInviteRepository,AuditLogEntryRepository}.php`
- Create: `backend/migrations/Version<ts>.php`
- Test: `backend/tests/Unit/AdminUserRolesTest.php`

**Interfaces:**
- Consumes: `AdminRole`, `AdminUserStatus` (Task 2).
- Produces: die vier Entities + Repos wie im Interface-Block; `AdminUser` implementiert `Symfony\Component\Security\Core\User\UserInterface`.

- [ ] **Step 1: Failing test für Rollen-Mapping**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminUserRolesTest extends TestCase
{
    public function testAdminHasAdminRole(): void
    {
        $u = new AdminUser('Chef', 'chef@example.com', AdminRole::Admin);
        self::assertSame(['ROLE_ADMIN'], $u->getRoles());
        self::assertSame('chef@example.com', $u->getUserIdentifier());
        self::assertSame(0, $u->credentialCount());
    }

    public function testModeratorHasModeratorRole(): void
    {
        $u = new AdminUser('Mod', 'mod@example.com', AdminRole::Moderator);
        self::assertSame(['ROLE_MODERATOR'], $u->getRoles());
    }
}
```

- [ ] **Step 2: Test läuft rot**

Run: `php backend/bin/phpunit --filter AdminUserRolesTest; echo $?`
Expected: FAIL (`AdminUser` existiert nicht).

- [ ] **Step 3: `AdminUser` implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Repository\AdminUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\Table(name: 'admin_user')]
class AdminUser implements UserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 190, unique: true)]
    public string $email;

    #[ORM\Column(length: 190)]
    public string $displayName;

    #[ORM\Column(length: 10, enumType: AdminRole::class)]
    public AdminRole $role;

    #[ORM\Column(length: 10, enumType: AdminUserStatus::class)]
    public AdminUserStatus $status = AdminUserStatus::Invited;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    /** @var Collection<int, WebAuthnCredential> */
    #[ORM\OneToMany(mappedBy: 'adminUser', targetEntity: WebAuthnCredential::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $credentials;

    public function __construct(string $displayName, string $email, AdminRole $role)
    {
        $this->displayName = $displayName;
        $this->email = $email;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $this->credentials = new ArrayCollection();
    }

    public function getRoles(): array
    {
        return $this->role === AdminRole::Admin ? ['ROLE_ADMIN'] : ['ROLE_MODERATOR'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }

    public function credentialCount(): int
    {
        return $this->credentials->count();
    }
}
```

- [ ] **Step 4: `WebAuthnCredential` implementieren** (serialisierter `CredentialRecord` als Wahrheitsquelle)

```php
<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebAuthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebAuthnCredential
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class, inversedBy: 'credentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public AdminUser $adminUser;

    /** Base64URL der publicKeyCredentialId (Lookup-Schlüssel). */
    #[ORM\Column(length: 255, unique: true)]
    public string $credentialId;

    /** Vollständiger, serialisierter CredentialRecord (JSON) — Wahrheitsquelle für die Assertion. */
    #[ORM\Column(type: 'text')]
    public string $source;

    #[ORM\Column(length: 64)]
    public string $label;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $aaguid = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(AdminUser $adminUser, string $credentialId, string $source, string $label)
    {
        $this->adminUser = $adminUser;
        $this->credentialId = $credentialId;
        $this->source = $source;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 5: `AdminInvite` implementieren** (Selector/Hash-Muster wie `Submitter`)

```php
<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\AdminRole;
use App\Repository\AdminInviteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminInviteRepository::class)]
#[ORM\Table(name: 'admin_invite')]
class AdminInvite
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public AdminUser $adminUser;

    #[ORM\Column(length: 10, enumType: AdminRole::class)]
    public AdminRole $role;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?AdminUser $createdBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(string $tokenSelector, string $tokenHash, AdminUser $adminUser, AdminRole $role, \DateTimeImmutable $expiresAt)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->adminUser = $adminUser;
        $this->role = $role;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 6: `AuditLogEntry` implementieren** (nur Insert)

```php
<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entry')]
#[ORM\Index(columns: ['created_at'])]
class AuditLogEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?AdminUser $actor = null;

    #[ORM\Column(length: 64)]
    public string $action;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $targetType = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $targetId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $detail = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(?AdminUser $actor, string $action, ?string $targetType = null, ?string $targetId = null, ?array $detail = null)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->detail = $detail;
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 7: Repositories** (jeweils `ServiceEntityRepository`-Muster)

```php
<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminUser> */
class AdminUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminUser::class);
    }

    public function findOneByEmail(string $email): ?AdminUser
    {
        return $this->findOneBy(['email' => $email]);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\WebAuthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WebAuthnCredential> */
class WebAuthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebAuthnCredential::class);
    }

    public function findOneByCredentialId(string $credentialId): ?WebAuthnCredential
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminInvite> */
class AdminInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminInvite::class);
    }

    public function findOneBySelector(string $selector): ?AdminInvite
    {
        return $this->findOneBy(['tokenSelector' => $selector]);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLogEntry> */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /** @return list<AuditLogEntry> */
    public function page(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();
    }
}
```

- [ ] **Step 8: Test grün**

Run: `php backend/bin/phpunit --filter AdminUserRolesTest; echo $?`
Expected: PASS, Exit-Code `0`.

- [ ] **Step 9: Migration erzeugen und prüfen**

```bash
php backend/bin/console doctrine:migrations:diff --no-interaction
```
Die erzeugte `Version<ts>.php` muss `CREATE TABLE` für `admin_user`, `webauthn_credential`, `admin_invite`, `audit_log_entry` enthalten (mit FKs `ON DELETE CASCADE`/`SET NULL`, `DEFAULT CHARACTER SET utf8mb4`). Manuell sichten. Dann anwenden (Test-DB nutzt Schema aus Migrationen bzw. dama rollt zurück):
```bash
php backend/bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 10: Volltest + Commit**

Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Entity backend/src/Repository backend/migrations backend/tests/Unit/AdminUserRolesTest.php
git commit -m "Ergänze Admin-Datenmodell und Migration"
```

---

### Task 4: `InviteTokenService` + `AuditLogger`

**Files:**
- Create: `backend/src/Service/InviteTokenService.php`, `backend/src/Service/GeneratedInvite.php`, `backend/src/Service/AuditLogger.php`
- Test: `backend/tests/Unit/InviteTokenServiceTest.php`

**Interfaces:**
- Consumes: `AdminUser` (Task 3).
- Produces: Signaturen wie im Interface-Block.

- [ ] **Step 1: Failing test für den Invite-Token**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Service\InviteTokenService;
use PHPUnit\Framework\TestCase;

final class InviteTokenServiceTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $svc = new InviteTokenService();
        $gen = $svc->generate();
        self::assertMatchesRegularExpression('/^gsta_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $gen->token);

        $parsed = $svc->parse($gen->token);
        self::assertNotNull($parsed);
        self::assertSame($gen->selector, $parsed['selector']);
        self::assertTrue($svc->verify($parsed['verifier'], $gen->hash));
        self::assertFalse($svc->verify('wrong', $gen->hash));
    }

    public function testParseRejectsGarbage(): void
    {
        self::assertNull((new InviteTokenService())->parse('nope'));
    }
}
```

- [ ] **Step 2: Rot laufen lassen**

Run: `php backend/bin/phpunit --filter InviteTokenServiceTest; echo $?`
Expected: FAIL.

- [ ] **Step 3: Implementieren** (spiegelt `EditTokenService`, Prefix `gsta_`)

```php
<?php
declare(strict_types=1);
namespace App\Service;

final readonly class GeneratedInvite
{
    public function __construct(
        public string $token,
        public string $selector,
        public string $hash,
    ) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Service;

final class InviteTokenService
{
    private const PATTERN = '/^gsta_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/';

    public function generate(): GeneratedInvite
    {
        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return new GeneratedInvite(
            token: sprintf('gsta_%s_%s', $selector, $verifier),
            selector: $selector,
            hash: password_hash($verifier, PASSWORD_ARGON2ID),
        );
    }

    /** @return array{selector:string,verifier:string}|null */
    public function parse(string $token): ?array
    {
        if (preg_match(self::PATTERN, $token, $m) !== 1) {
            return null;
        }
        return ['selector' => $m[1], 'verifier' => $m[2]];
    }

    public function verify(string $verifier, string $hash): bool
    {
        return password_verify($verifier, $hash);
    }
}
```

- [ ] **Step 4: `AuditLogger` implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLogEntry;
use Doctrine\ORM\EntityManagerInterface;

final class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function log(?AdminUser $actor, string $action, ?string $targetType = null, ?string $targetId = null, ?array $detail = null): void
    {
        $this->em->persist(new AuditLogEntry($actor, $action, $targetType, $targetId, $detail));
        $this->em->flush();
    }
}
```

- [ ] **Step 5: Test grün + Commit**

Run: `php backend/bin/phpunit --filter InviteTokenServiceTest; echo $?` → PASS.
```bash
git add backend/src/Service/InviteTokenService.php backend/src/Service/GeneratedInvite.php backend/src/Service/AuditLogger.php backend/tests/Unit/InviteTokenServiceTest.php
git commit -m "Ergänze Invite-Token-Service und Audit-Logger"
```

---

### Task 5: Security-Firewall, `AdminSession`, Session-Authenticator

Rollen-Gating über eine eigene Firewall, die unser httpOnly-Session-Cookie liest. Das ist der Kern des Hybrid-Ansatzes.

**Files:**
- Create: `backend/config/packages/security.yaml` (ersetzt das temporäre aus Task 1)
- Modify: `backend/config/packages/framework.yaml` (Session-Cookie-Attribute)
- Create: `backend/src/Service/AdminSession.php`
- Create: `backend/src/Security/AdminSessionAuthenticator.php`
- Test: `backend/tests/Functional/Admin/FirewallTest.php`
- Test: `backend/tests/Functional/Admin/AdminTestCase.php` (Basisklasse für Admin-Tests)

**Interfaces:**
- Consumes: `AdminUser`, `AdminUserRepository` (Task 3).
- Produces: `AdminSession` (siehe Interface-Block), Firewall `admin` auf `^/api/admin`.

- [ ] **Step 1: `AdminSession` implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\AdminUser;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminSession
{
    private const KEY_USER = '_admin_user_id';
    private const KEY_VERIFIED = '_admin_verified_at';
    private const KEY_CHALLENGE = '_admin_challenge_';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function login(AdminUser $u): void
    {
        $s = $this->requestStack->getSession();
        $s->set(self::KEY_USER, $u->id);
        $s->set(self::KEY_VERIFIED, time());
    }

    public function currentUserId(): ?int
    {
        return $this->requestStack->getSession()->get(self::KEY_USER);
    }

    public function markVerified(): void
    {
        $this->requestStack->getSession()->set(self::KEY_VERIFIED, time());
    }

    public function isFresh(int $maxAgeSeconds): bool
    {
        $ts = $this->requestStack->getSession()->get(self::KEY_VERIFIED);
        return is_int($ts) && (time() - $ts) <= $maxAgeSeconds;
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }

    public function putChallenge(string $key, string $challengeB64): void
    {
        $this->requestStack->getSession()->set(self::KEY_CHALLENGE . $key, $challengeB64);
    }

    public function takeChallenge(string $key): ?string
    {
        $s = $this->requestStack->getSession();
        $v = $s->get(self::KEY_CHALLENGE . $key);
        $s->remove(self::KEY_CHALLENGE . $key);
        return is_string($v) ? $v : null;
    }
}
```

- [ ] **Step 2: `AdminSessionAuthenticator` implementieren**

Der Authenticator übersetzt die Session-User-ID in ein Symfony-Token. `supports()` greift nur, wenn eine Session-User-ID existiert; ohne Session bleibt der Request anonym und `access_control` wirft 401 über den `entry_point`.

```php
<?php
declare(strict_types=1);
namespace App\Security;

use App\Service\AdminSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class AdminSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly AdminSession $session) {}

    public function supports(Request $request): ?bool
    {
        return $this->session->currentUserId() !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $userId = $this->session->currentUserId();
        if ($userId === null) {
            throw new AuthenticationException('No admin session');
        }
        // UserBadge lädt AdminUser über den konfigurierten Provider (email). Wir kennen
        // hier nur die ID → Loader-Callback nutzt das Repository per ID.
        return new SelfValidatingPassport(new UserBadge((string) $userId, null));
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        return null; // Request normal weiterlaufen lassen
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        $r = new JsonResponse(['type' => 'about:blank', 'title' => 'Authentication required', 'status' => 401], 401);
        $r->headers->set('Content-Type', 'application/problem+json');
        return $r;
    }
}
```

Damit `UserBadge` mit der ID (statt E-Mail) lädt, braucht der Provider einen ID-Loader. Einfachste konsistente Lösung: den Provider als **Custom-Loader** über das Repository konfigurieren (Step 3), das `loadUserByIdentifier($id)` als ID interpretiert. Alternativ speichert `AdminSession::login()` zusätzlich die E-Mail und der Authenticator nutzt sie als Identifier. **Wir wählen die E-Mail-Variante** (weniger Custom-Code):

Ergänze in `AdminSession`:
```php
private const KEY_EMAIL = '_admin_user_email';
// in login():   $s->set(self::KEY_EMAIL, $u->email);
public function currentUserEmail(): ?string
{
    return $this->requestStack->getSession()->get(self::KEY_EMAIL);
}
// in logout() nichts extra (invalidate() löscht alles)
```
und im Authenticator `authenticate()`:
```php
$email = $this->session->currentUserEmail();
if ($email === null) { throw new AuthenticationException('No admin session'); }
return new SelfValidatingPassport(new UserBadge($email));
```
So lädt der Standard-Entity-Provider (`property: email`) den Nutzer ohne Custom-Loader.

- [ ] **Step 3: `security.yaml` schreiben**

```yaml
security:
    providers:
        admin_users:
            entity:
                class: App\Entity\AdminUser
                property: email
    role_hierarchy:
        ROLE_ADMIN: [ROLE_MODERATOR]
    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        admin:
            pattern: ^/api/admin
            provider: admin_users
            stateless: false
            custom_authenticators:
                - App\Security\AdminSessionAuthenticator
            entry_point: App\Security\AdminSessionAuthenticator
        public:
            pattern: ^/
            security: false
    access_control:
        - { path: ^/api/admin/auth/options, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/auth/login, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/register, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/users, roles: ROLE_ADMIN }
        - { path: ^/api/admin/audit, roles: ROLE_ADMIN }
        - { path: ^/api/admin, roles: ROLE_MODERATOR }
```
Hinweis: `access_control` ist first-match-wins. `auth/me`, `auth/logout`, `stepup`, `credentials`, Moderationspfade fallen auf `ROLE_MODERATOR`; `users` und `audit` verlangen `ROLE_ADMIN`. `ROLE_ADMIN` erbt `ROLE_MODERATOR` über `role_hierarchy`.

- [ ] **Step 4: Session-Cookie in `framework.yaml`**

Ergänze/ersetze den `session`-Block (der bestehende Wert `session: true` wird konkretisiert; die Test-Umgebung überschreibt `storage_factory_id` bereits):
```yaml
framework:
    session:
        cookie_httponly: true
        cookie_secure: auto
        cookie_samesite: strict
        cookie_domain: '%env(default::SESSION_COOKIE_DOMAIN)%'
        gc_maxlifetime: 1800
        cookie_lifetime: 0
```
`SESSION_COOKIE_DOMAIN` in `.env` leer lassen (lokal); auf dem Server in `.env.local` `SESSION_COOKIE_DOMAIN=.gestura.eu`.

- [ ] **Step 5: Admin-Test-Basisklasse + Firewall-Test**

`AdminTestCase` legt Fixtures direkt über den EntityManager an und bietet Helfer, um eine authentifizierte Session zu simulieren (ohne echten Authenticator — wir setzen die Session serverseitig über einen Test-only-Login-Helper, indem wir `AdminSession` aus dem Container nutzen). Da der `KernelBrowser` Cookies über Requests hält, loggen wir über einen echten (gemockten) Login in Task 7 ein; für den reinen Firewall-Test genügt der Nachweis, dass **ohne** Session 401 kommt.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
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
}
```
```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

final class FirewallTest extends AdminTestCase
{
    public function testQueueRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/queue');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testUnknownAdminPathStillProblemJson(): void
    {
        $this->client->request('GET', '/api/admin/nope');
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 6: Test + Commit**

Run: `php backend/bin/phpunit --filter FirewallTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0` (Regressionscheck: öffentliche API unberührt).
```bash
git add backend/config/packages/security.yaml backend/config/packages/framework.yaml backend/src/Service/AdminSession.php backend/src/Security/AdminSessionAuthenticator.php backend/tests/Functional/Admin
git commit -m "Ergänze Admin-Firewall mit Session-Authenticator"
```

---

### Task 6: WebAuthn-Ceremony-Wrapper + Test-Fake

Kapselt Options-Erzeugung und Attestation-/Assertion-Prüfung. Wird in Tests durch einen deterministischen Fake ersetzt (kein echter Authenticator).

**Files:**
- Create: `backend/config/packages/webauthn.yaml`
- Create: `backend/src/Service/WebAuthn/WebAuthnCeremony.php` (Interface)
- Create: `backend/src/Service/WebAuthn/BundleWebAuthnCeremony.php` (echte Implementierung)
- Create: `backend/src/Service/WebAuthn/FakeWebAuthnCeremony.php` (Test-Umgebung)
- Modify: `backend/config/services.yaml` (Alias in `when@test` auf den Fake)
- Test: `backend/tests/Functional/Admin/CeremonyWiringTest.php`

**Interfaces:**
- Consumes: `AdminUser`, `WebAuthnCredential(Repository)`, `AdminSession` (Tasks 3, 5).
- Produces: `WebAuthnCeremony` (siehe Interface-Block).

- [ ] **Step 1: `webauthn.yaml`** (RP-ID/-Name; ein Creation- und ein Request-Profil)

```yaml
webauthn:
    credential_repository: App\Service\WebAuthn\DoctrineCredentialSourceRepository
    user_repository: App\Service\WebAuthn\DoctrineUserEntityRepository
    creation_profiles:
        admin:
            rp:
                name: 'Gestura-Index Admin'
                id: '%env(default:default_rp_id:WEBAUTHN_RP_ID)%'
            authenticator_selection_criteria:
                user_verification: required
    request_profiles:
        admin:
            rp_id: '%env(default:default_rp_id:WEBAUTHN_RP_ID)%'
            user_verification: required
parameters:
    default_rp_id: 'gestura.eu'
```
Falls die installierte Bundle-Version andere Config-Schlüssel erwartet (siehe Task 1, Step 3), gegen `php backend/bin/console config:dump-reference webauthn` abgleichen und anpassen.

- [ ] **Step 2: `WebAuthnCeremony`-Interface**

```php
<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;

interface WebAuthnCeremony
{
    public function creationOptionsJson(AdminUser $user): string;
    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential;
    public function requestOptionsJson(?AdminUser $user): string;
    public function verifyAssertion(string $clientJson): AdminUser;
}
```

- [ ] **Step 3: Echte Implementierung** (nutzt Bundle-Services; Challenge in der Session)

> **Verifikationshinweis (Task 1, Step 3):** Konstruktor-Typen unten sind die 5.3.x-Namen. Falls `CredentialRecord` fehlt, `PublicKeyCredentialSource` verwenden. Der Serializer kommt aus `WebauthnSerializerFactory` (im Bundle als Service registriert) — per Autowiring injizieren; andernfalls im Konstruktor `WebauthnSerializerFactory::create()` aufrufen.

```php
<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use App\Service\AdminSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

final class BundleWebAuthnCeremony implements WebAuthnCeremony
{
    public function __construct(
        private readonly PublicKeyCredentialCreationOptionsFactory $creationFactory,
        private readonly PublicKeyCredentialRequestOptionsFactory $requestFactory,
        private readonly AuthenticatorAttestationResponseValidator $attestationValidator,
        private readonly AuthenticatorAssertionResponseValidator $assertionValidator,
        private readonly SerializerInterface $webauthnSerializer,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly AdminSession $session,
        private readonly EntityManagerInterface $em,
        private readonly string $rpId, // '%env(default:default_rp_id:WEBAUTHN_RP_ID)%' via #[Autowire]
    ) {}

    public function creationOptionsJson(AdminUser $user): string
    {
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->email,
            (string) $user->id,
            $user->displayName,
        );
        $excluded = [];
        foreach ($user->credentials as $c) {
            $excluded[] = $this->deserializeSource($c->source)->publicKeyCredentialId;
        }
        $options = $this->creationFactory->create('admin', $userEntity, $excluded);
        $this->session->putChallenge('reg', base64_encode($options->challenge));
        return $this->webauthnSerializer->serialize($options, 'json');
    }

    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential
    {
        $challenge = $this->session->takeChallenge('reg') ?? throw new ApiProblem(400, 'No registration challenge');
        $options = $this->rebuildCreationOptions($user, base64_decode($challenge));
        $pkc = $this->webauthnSerializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
        if (!$pkc->response instanceof AuthenticatorAttestationResponse) {
            throw new ApiProblem(400, 'Invalid attestation response');
        }
        try {
            $record = $this->attestationValidator->check($pkc->response, $options, $this->rpId);
        } catch (\Throwable) {
            throw new ApiProblem(400, 'Attestation verification failed');
        }
        $credId = $this->b64url($record->publicKeyCredentialId);
        $cred = new WebAuthnCredential($user, $credId, $this->webauthnSerializer->serialize($record, 'json'), $label);
        $cred->aaguid = method_exists($record, 'getAaguid') ? (string) $record->getAaguid() : null;
        $this->em->persist($cred);
        $this->em->flush();
        return $cred;
    }

    public function requestOptionsJson(?AdminUser $user): string
    {
        $allow = [];
        if ($user !== null) {
            foreach ($user->credentials as $c) {
                $allow[] = $this->deserializeSource($c->source)->publicKeyCredentialId;
            }
        }
        $options = $this->requestFactory->create('admin', $allow);
        $this->session->putChallenge('assert', base64_encode($options->challenge));
        return $this->webauthnSerializer->serialize($options, 'json');
    }

    public function verifyAssertion(string $clientJson): AdminUser
    {
        $challenge = $this->session->takeChallenge('assert') ?? throw new ApiProblem(400, 'No assertion challenge');
        $pkc = $this->webauthnSerializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
        if (!$pkc->response instanceof AuthenticatorAssertionResponse) {
            throw new ApiProblem(400, 'Invalid assertion response');
        }
        $credId = $this->b64url($pkc->rawId);
        $cred = $this->credentials->findOneByCredentialId($credId) ?? throw new ApiProblem(401, 'Unknown credential');
        $record = $this->deserializeSource($cred->source);
        $options = $this->rebuildRequestOptions(base64_decode($challenge));
        try {
            $updated = $this->assertionValidator->check(
                $record,
                $pkc->response,
                $options,
                $this->rpId,
                (string) $cred->adminUser->id,
            );
        } catch (\Throwable) {
            throw new ApiProblem(401, 'Assertion verification failed');
        }
        $cred->source = $this->webauthnSerializer->serialize($updated, 'json');
        $cred->lastUsedAt = new \DateTimeImmutable();
        $this->em->flush();
        return $cred->adminUser;
    }

    private function deserializeSource(string $json): CredentialRecord
    {
        return $this->webauthnSerializer->deserialize($json, CredentialRecord::class, 'json');
    }

    private function rebuildCreationOptions(AdminUser $user, string $challenge): PublicKeyCredentialCreationOptions
    {
        $userEntity = PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, $user->displayName);
        $options = $this->creationFactory->create('admin', $userEntity, []);
        $options->challenge = $challenge; // exakte Challenge der Options-Ausgabe
        return $options;
    }

    private function rebuildRequestOptions(string $challenge): PublicKeyCredentialRequestOptions
    {
        $options = $this->requestFactory->create('admin', []);
        $options->challenge = $challenge;
        return $options;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
```
> **Hinweis Challenge-Handling:** Die Options-Factory erzeugt bei jedem Aufruf eine neue Zufalls-Challenge; für die Verifikation muss exakt die dem Client ausgelieferte Challenge zurückgesetzt werden (`$options->challenge = ...`). Falls `challenge` in der installierten Version nicht öffentlich schreibbar ist, die Options **einmal** bauen, serialisieren und die serialisierte Form in der Session ablegen, in `verify*` deserialisieren. Beim Umbau in Task 1/Step 3 prüfen.

- [ ] **Step 4: Repository-Adapter für das Bundle** (falls die gewählte Bundle-Konfiguration sie erwartet)

`DoctrineCredentialSourceRepository` und `DoctrineUserEntityRepository` implementieren die vom Bundle geforderten Interfaces (Namen gemäß Task-1-Verifikation, i. d. R. `Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface` und `PublicKeyCredentialUserEntityRepositoryInterface`) und delegieren an `WebAuthnCredentialRepository`/`AdminUserRepository`. Da wir die Validatoren **direkt** aufrufen, werden diese Adapter nur benötigt, wenn `webauthn.yaml` sie als Pflichtfelder verlangt. Minimal-Implementierung:

```php
<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Repository\WebAuthnCredentialRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\CredentialRecord;

final class DoctrineCredentialSourceRepository implements PublicKeyCredentialSourceRepositoryInterface
{
    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly SerializerInterface $webauthnSerializer,
    ) {}

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        $b64 = rtrim(strtr(base64_encode($publicKeyCredentialId), '+/', '-_'), '=');
        $c = $this->credentials->findOneByCredentialId($b64);
        return $c === null ? null : $this->webauthnSerializer->deserialize($c->source, CredentialRecord::class, 'json');
    }

    /** @return CredentialRecord[] */
    public function findAllForUserEntity($publicKeyCredentialUserEntity): array
    {
        return []; // Nicht benötigt für den direkten Validator-Aufruf; leer ist zulässig.
    }

    public function saveCredentialSource(CredentialRecord $credentialRecord): void
    {
        // No-op: Persistenz erfolgt in WebAuthnCeremony. Bei abweichender Signatur (Task 1) anpassen.
    }
}
```
`DoctrineUserEntityRepository` analog gegen `PublicKeyCredentialUserEntityRepositoryInterface` (`findWebauthnUserByUsername`, `findWebauthnUserByUserHandle`, `generateUserEntity`, `saveUserEntity` — leere/Repository-gestützte Umsetzung). Falls die Bundle-Version die Repositories **nicht** zwingend verlangt, diesen Step überspringen und `webauthn.yaml` ohne `credential_repository`/`user_repository` konfigurieren.

- [ ] **Step 5: Test-Fake + Alias in `when@test`**

Der Fake macht Tests deterministisch: `creationOptionsJson`/`requestOptionsJson` liefern konstantes JSON und legen die Challenge in die Session; `verifyRegistration` legt ein echtes `WebAuthnCredential` an (mit einem im Client-JSON übergebenen Pseudo-Credential-ID); `verifyAssertion` findet den Nutzer über die im Client-JSON übergebene Credential-ID.

```php
<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;

final class FakeWebAuthnCeremony implements WebAuthnCeremony
{
    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly EntityManagerInterface $em,
    ) {}

    public function creationOptionsJson(AdminUser $user): string
    {
        return json_encode(['challenge' => 'fake-reg', 'rp' => ['id' => 'gestura.eu']], JSON_THROW_ON_ERROR);
    }

    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential
    {
        $data = json_decode($clientJson, true, 8, JSON_THROW_ON_ERROR);
        $credId = $data['id'] ?? throw new ApiProblem(400, 'Fake: missing id');
        $cred = new WebAuthnCredential($user, $credId, $clientJson, $label);
        $this->em->persist($cred);
        $this->em->flush();
        return $cred;
    }

    public function requestOptionsJson(?AdminUser $user): string
    {
        return json_encode(['challenge' => 'fake-assert'], JSON_THROW_ON_ERROR);
    }

    public function verifyAssertion(string $clientJson): AdminUser
    {
        $data = json_decode($clientJson, true, 8, JSON_THROW_ON_ERROR);
        $credId = $data['id'] ?? throw new ApiProblem(400, 'Fake: missing id');
        $cred = $this->credentials->findOneByCredentialId($credId) ?? throw new ApiProblem(401, 'Fake: unknown credential');
        $cred->lastUsedAt = new \DateTimeImmutable();
        $this->em->flush();
        return $cred->adminUser;
    }
}
```
In `config/services.yaml` den Interface-Alias setzen (Default → echte Impl., Test → Fake):
```yaml
services:
    App\Service\WebAuthn\WebAuthnCeremony: '@App\Service\WebAuthn\BundleWebAuthnCeremony'

when@test:
    services:
        App\Service\WebAuthn\WebAuthnCeremony: '@App\Service\WebAuthn\FakeWebAuthnCeremony'
```
Und den `$rpId`-Parameter der echten Impl. per Attribut binden:
```php
// im Konstruktor von BundleWebAuthnCeremony:
#[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:default_rp_id:WEBAUTHN_RP_ID)%')] string $rpId,
```

- [ ] **Step 6: Wiring-Test** (Container baut, Fake ist aktiv in test)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Service\WebAuthn\FakeWebAuthnCeremony;
use App\Service\WebAuthn\WebAuthnCeremony;

final class CeremonyWiringTest extends AdminTestCase
{
    public function testTestEnvUsesFake(): void
    {
        $svc = static::getContainer()->get(WebAuthnCeremony::class);
        self::assertInstanceOf(FakeWebAuthnCeremony::class, $svc);
    }
}
```

- [ ] **Step 7: Test + Commit**

Run: `php backend/bin/phpunit --filter CeremonyWiringTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/config/packages/webauthn.yaml backend/config/services.yaml backend/src/Service/WebAuthn backend/tests/Functional/Admin/CeremonyWiringTest.php
git commit -m "Ergänze WebAuthn-Ceremony-Wrapper und Test-Fake"
```

---

### Task 7: Auth-Flow (Login/Logout/Me) + `StepUpGuard`

**Files:**
- Create: `backend/src/Controller/Admin/AuthOptionsController.php`, `AuthLoginController.php`, `AuthLogoutController.php`, `AuthMeController.php`, `StepUpOptionsController.php`, `StepUpController.php`
- Create: `backend/src/Security/StepUpGuard.php`
- Test: `backend/tests/Functional/Admin/AuthFlowTest.php`

**Interfaces:**
- Consumes: `WebAuthnCeremony`, `AdminSession`, `AuditLogger`, `AdminUserRepository`, `StepUpGuard`.
- Produces: Login setzt Session; `GET /api/admin/auth/me` → `{ email, displayName, role, credentialCount, stepUpFresh }`.

- [ ] **Step 1: `StepUpGuard`**

```php
<?php
declare(strict_types=1);
namespace App\Security;

use App\Exception\ApiProblem;
use App\Service\AdminSession;

final class StepUpGuard
{
    private const MAX_AGE = 300;

    public function __construct(private readonly AdminSession $session) {}

    public function assertFresh(): void
    {
        if (!$this->session->isFresh(self::MAX_AGE)) {
            throw new ApiProblem(403, 'Step-up required', ['stepUpRequired' => true]);
        }
    }
}
```

- [ ] **Step 2: Failing test des Login-Flows** (mit Fake-Ceremony)

Der Test legt einen aktiven Admin mit **einem** registrierten Credential an (direkt über EM), fragt Options ab, postet die Assertion (Fake nutzt `id`), erwartet 204 + Session-Cookie, dann `auth/me` == 200.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;

final class AuthFlowTest extends AdminTestCase
{
    public function testLoginThenMe(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-1', '{"id":"cred-1"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();

        // Options holen (setzt Challenge in Session)
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        self::assertResponseIsSuccessful();

        // Assertion posten (Fake liest 'id')
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => 'cred-1'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // me
        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('chef@example.com', $this->json()['email']);
        self::assertSame('admin', $this->json()['role']);
    }

    public function testMeWithoutSessionIs401(): void
    {
        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string,string> */
    private function hdr(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }
}
```

- [ ] **Step 3: Rot laufen lassen**

Run: `php backend/bin/phpunit --filter AuthFlowTest; echo $?`
Expected: FAIL (Controller fehlen).

- [ ] **Step 4: Controller implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\AdminSession;
use App\Service\WebAuthn\WebAuthnCeremony;
use App\Exception\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AuthOptionsController
{
    #[Route('/api/admin/auth/options', methods: ['POST'])]
    public function __invoke(WebAuthnCeremony $ceremony): JsonResponse
    {
        // Usernameless/discoverable Login: keine User-Bindung nötig
        return JsonResponse::fromJsonString($ceremony->requestOptionsJson(null));
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Service\AdminSession;
use App\Service\AuditLogger;
use App\Service\RateLimitGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthLoginController
{
    #[Route('/api/admin/auth/login', methods: ['POST'])]
    public function __invoke(
        Request $request,
        WebAuthnCeremony $ceremony,
        AdminSession $session,
        AuditLogger $audit,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $adminLoginLimiter,
    ): Response {
        $guard->consume($adminLoginLimiter, $request->getClientIp() ?? 'unknown');
        $user = $ceremony->verifyAssertion($request->getContent());
        if ($user->status !== AdminUserStatus::Active) {
            throw new ApiProblem(403, 'Account is not active');
        }
        $session->login($user);
        $user->lastLoginAt = new \DateTimeImmutable();
        $audit->log($user, 'auth.login');
        return new Response('', 204);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\AdminSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthLogoutController
{
    #[Route('/api/admin/auth/logout', methods: ['POST'])]
    public function __invoke(AdminSession $session): Response
    {
        $session->logout();
        return new Response('', 204);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Service\AdminSession;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthMeController
{
    #[Route('/api/admin/auth/me', methods: ['GET'])]
    public function __invoke(Security $security, AdminSession $session): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        return new JsonResponse([
            'email' => $user->email,
            'displayName' => $user->displayName,
            'role' => $user->role->value,
            'credentialCount' => $user->credentialCount(),
            'stepUpFresh' => $session->isFresh(300),
        ]);
    }
}
```
Step-up-Controller (frische Assertion für den eingeloggten Nutzer):
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StepUpOptionsController
{
    #[Route('/api/admin/stepup/options', methods: ['POST'])]
    public function __invoke(Security $security, WebAuthnCeremony $ceremony): JsonResponse
    {
        return JsonResponse::fromJsonString($ceremony->requestOptionsJson($security->getUser()));
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Service\AdminSession;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StepUpController
{
    #[Route('/api/admin/stepup', methods: ['POST'])]
    public function __invoke(Request $request, Security $security, WebAuthnCeremony $ceremony, AdminSession $session): Response
    {
        /** @var AdminUser $current */
        $current = $security->getUser();
        $verified = $ceremony->verifyAssertion($request->getContent());
        if ($verified->id !== $current->id) {
            throw new ApiProblem(403, 'Step-up credential does not match current user');
        }
        $session->markVerified();
        return new Response('', 204);
    }
}
```

- [ ] **Step 5: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter AuthFlowTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Controller/Admin/Auth*.php backend/src/Controller/Admin/StepUp*.php backend/src/Security/StepUpGuard.php backend/tests/Functional/Admin/AuthFlowTest.php
git commit -m "Ergänze Admin-Auth-Flow und Step-up-Guard"
```

---

### Task 8: Passkey-Verwaltung + `BackupPasskeyGate`

**Files:**
- Create: `backend/src/Security/BackupPasskeyGate.php`
- Create: `backend/src/Controller/Admin/CredentialListController.php`, `CredentialAddOptionsController.php`, `CredentialAddController.php`, `CredentialLabelController.php`, `CredentialRemoveController.php`
- Test: `backend/tests/Functional/Admin/CredentialTest.php`

**Interfaces:**
- Consumes: `WebAuthnCeremony`, `Security`, `AdminSession`, `StepUpGuard`, `BackupPasskeyGate`, `WebAuthnCredentialRepository`.
- Produces: Entfernen unter 2 → 409; Add erhöht `credentialCount`.

- [ ] **Step 1: `BackupPasskeyGate`**

```php
<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;

final class BackupPasskeyGate
{
    public function assertEnough(AdminUser $u): void
    {
        if ($u->credentialCount() < 2) {
            throw new ApiProblem(409, 'Backup passkey required', ['backupRequired' => true]);
        }
    }
}
```

- [ ] **Step 2: Failing test** (Login mit 1 Passkey → 2. hinzufügen → entfernen bis auf 2 ok, drunter 409)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;

final class CredentialTest extends AdminTestCase
{
    public function testAddSecondAndRemoveGuard(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-1', '{"id":"cred-1"}', 'Laptop'));
        $this->em->flush();
        $this->loginAs('cred-1');

        // zweiten Passkey hinzufügen
        $this->client->request('POST', '/api/admin/credentials/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/credentials', server: $this->hdr(),
            content: json_encode(['id' => 'cred-2', 'label' => 'Handy'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Liste zeigt 2
        $this->client->request('GET', '/api/admin/credentials', server: $this->hdr());
        self::assertCount(2, $this->json());

        // einen entfernen (bleibt ... < 2) → 409; hier: von 2 auf 1 => 409
        $credId = $this->json()[0]['id'];
        $this->client->request('POST', "/api/admin/credentials/{$credId}/remove", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    protected function loginAs(string $credentialId): void
    {
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => $credentialId], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    protected function hdr(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }
}
```
> `loginAs`/`hdr` in eine gemeinsame `AdminTestCase` heben, sobald mehrere Tests sie brauchen (Task 9+). Für jetzt lokal ausreichend.

- [ ] **Step 3: Rot laufen lassen**

Run: `php backend/bin/phpunit --filter CredentialTest; echo $?` → FAIL.

- [ ] **Step 4: Controller implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialListController
{
    #[Route('/api/admin/credentials', methods: ['GET'])]
    public function __invoke(Security $security): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        $out = [];
        foreach ($user->credentials as $c) {
            $out[] = [
                'id' => $c->id,
                'label' => $c->label,
                'createdAt' => $c->createdAt->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $c->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ];
        }
        return new JsonResponse($out);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialAddOptionsController
{
    #[Route('/api/admin/credentials/options', methods: ['POST'])]
    public function __invoke(Security $security, WebAuthnCeremony $ceremony): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        return JsonResponse::fromJsonString($ceremony->creationOptionsJson($user));
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialAddController
{
    #[Route('/api/admin/credentials', methods: ['POST'])]
    public function __invoke(Request $request, Security $security, WebAuthnCeremony $ceremony): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $label = is_string($body['label'] ?? null) && $body['label'] !== '' ? $body['label'] : 'Passkey';
        $cred = $ceremony->verifyRegistration($user, $request->getContent(), mb_substr($label, 0, 64));
        return new JsonResponse(['id' => $cred->id, 'label' => $cred->label], 201);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialLabelController
{
    #[Route('/api/admin/credentials/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request, Security $security, WebAuthnCredentialRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        $cred = $repo->find($id);
        if ($cred === null || $cred->adminUser->id !== $user->id) {
            throw new ApiProblem(404, 'Credential not found');
        }
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $label = $body['label'] ?? null;
        if (!is_string($label) || $label === '') {
            throw new ApiProblem(400, 'label is required');
        }
        $cred->label = mb_substr($label, 0, 64);
        $em->flush();
        return new JsonResponse(['id' => $cred->id, 'label' => $cred->label]);
    }
}
```
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialRemoveController
{
    #[Route('/api/admin/credentials/{id}/remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Security $security, WebAuthnCredentialRepository $repo, EntityManagerInterface $em, StepUpGuard $stepUp, AuditLogger $audit): Response
    {
        $stepUp->assertFresh();
        /** @var AdminUser $user */
        $user = $security->getUser();
        $cred = $repo->find($id);
        if ($cred === null || $cred->adminUser->id !== $user->id) {
            throw new ApiProblem(404, 'Credential not found');
        }
        if ($user->credentialCount() <= 2) {
            throw new ApiProblem(409, 'At least two passkeys required', ['backupRequired' => true]);
        }
        $audit->log($user, 'credential.remove', 'credential', (string) $cred->id);
        $em->remove($cred);
        $em->flush();
        return new Response('', 204);
    }
}
```
> Der Test in Step 2 hat frisch eingeloggt (verifiedAt = jetzt), daher ist `StepUpGuard::assertFresh()` erfüllt; das 409 kommt aus der `<= 2`-Regel.

- [ ] **Step 5: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter CredentialTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Security/BackupPasskeyGate.php backend/src/Controller/Admin/Credential*.php backend/tests/Functional/Admin/CredentialTest.php
git commit -m "Ergänze Passkey-Verwaltung mit Backup-Pflicht"
```

---

### Task 9: Moderations-Endpunkte

Dünne Controller über `ModerationService`; destruktive Aktionen (`reject`, `ban`) sind Step-up- und Backup-Gate-geschützt und schreiben Audit-Log. `ModerationService` wirft `\RuntimeException` → in `ApiProblem(409)` übersetzen.

**Files:**
- Create: `backend/src/Controller/Admin/QueueController.php`, `AdminEntryDetailController.php`, `EntryApproveController.php`, `EntryRejectController.php`, `VersionApproveController.php`, `VersionRejectController.php`, `ReportListController.php`, `ReportResolveController.php`, `SubmitterBanController.php`, `SubmitterUnbanController.php`
- Test: `backend/tests/Functional/Admin/ModerationTest.php`
- Modify: `backend/tests/Functional/Admin/AdminTestCase.php` (Login-Helfer + `hdr()` hochziehen)

**Interfaces:**
- Consumes: `ModerationService` (`approveEntry/rejectEntry/approveVersion/rejectVersion/resolveReport/ban/unban`), Repositories, `Security`, `StepUpGuard`, `BackupPasskeyGate`, `AuditLogger`, `EntrySerializer`.
- Produces: HTTP-Zustandsübergänge + Audit-Einträge.

- [ ] **Step 1: `AdminTestCase` um Login-Helfer erweitern**

```php
// ergänzen in AdminTestCase:
protected function hdr(): array
{
    return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
}

protected function loginWithCredentials(\App\Entity\AdminUser $u, int $count = 2): void
{
    for ($i = 1; $i <= $count; $i++) {
        $this->em->persist(new \App\Entity\WebAuthnCredential($u, "cred-{$u->id}-{$i}", '{"id":"x"}', "Key {$i}"));
    }
    $this->em->flush();
    $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
    $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
        content: json_encode(['id' => "cred-{$u->id}-1"], JSON_THROW_ON_ERROR));
}
```

- [ ] **Step 2: Failing test** (approve Entry, reject Version mit Step-up, ban mit Audit)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Enum\AdminRole;

final class ModerationTest extends AdminTestCase
{
    public function testApproveEntryWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPendingEntry('com.example.pending'); // Helfer in ApiTestCase (siehe Hinweis)

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, fn($a) => $a->action === 'entry.approve'));
    }
}
```
> **Fixture-Hinweis:** Falls in `ApiTestCase` noch kein `createPendingEntry()` existiert, in `AdminTestCase` ergänzen: einen `Entry` mit `status = EntryStatus::Pending` + zugehörige `EntryVersion` (status `Pending`) über den EM anlegen (analog zum vorhandenen `createPublishedEntry`, aber ohne `currentVersion`, damit die Statusmaschinen-Invariante gilt: `pending` ⇒ `currentVersion === null`).

- [ ] **Step 3: Rot laufen lassen**

Run: `php backend/bin/phpunit --filter ModerationTest; echo $?` → FAIL.

- [ ] **Step 4: Controller implementieren** (Muster: `try { $moderation->…(); } catch (\RuntimeException $e) { throw new ApiProblem(409, $e->getMessage()); }`)

`QueueController` (offene Einträge + Versionen; transformCode hervorheben):
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\PayloadAnalyzer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class QueueController
{
    #[Route('/api/admin/queue', methods: ['GET'])]
    public function __invoke(EntryRepository $entries, EntryVersionRepository $versions): JsonResponse
    {
        $pendingEntries = $entries->findBy(['status' => EntryStatus::Pending], ['createdAt' => 'ASC']);
        $out = ['entries' => [], 'versions' => []];
        foreach ($pendingEntries as $e) {
            $out['entries'][] = ['id' => $e->id, 'formatId' => $e->formatId, 'type' => $e->type->value, 'createdAt' => $e->createdAt->format(\DateTimeInterface::ATOM)];
        }
        foreach ($versions->findBy(['status' => VersionStatus::Pending], ['id' => 'ASC']) as $v) {
            $out['versions'][] = [
                'id' => $v->id,
                'entryId' => $v->entry->id,
                'semver' => $v->semver,
                'hasTransform' => str_contains($v->payloadJson ?? '', 'transformCode'),
            ];
        }
        return new JsonResponse($out);
    }
}
```
> Feldnamen (`payloadJson`) gegen die tatsächliche `EntryVersion`-Property abgleichen; alternativ `PayloadAnalyzer::hasTransform(json_decode(...))` verwenden.

`EntryApproveController`:
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryApproveController
{
    #[Route('/api/admin/entries/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryRepository $entries, ModerationService $moderation, AuditLogger $audit, Security $security): Response
    {
        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');
        try {
            $moderation->approveEntry($entry);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $audit->log($actor, 'entry.approve', 'entry', (string) $entry->id);
        return new Response('', 204);
    }
}
```
`EntryRejectController` (Step-up + Backup-Gate):
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryRejectController
{
    #[Route('/api/admin/entries/{id}/reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryRepository $entries, ModerationService $moderation, AuditLogger $audit, Security $security, StepUpGuard $stepUp, BackupPasskeyGate $backup): Response
    {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();
        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');
        try {
            $moderation->rejectEntry($entry);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }
        $audit->log($actor, 'entry.reject', 'entry', (string) $entry->id);
        return new Response('', 204);
    }
}
```
`VersionApproveController` / `VersionRejectController` analog gegen `EntryVersionRepository` + `approveVersion`/`rejectVersion` (`version.approve`/`version.reject`; reject = Step-up + Backup-Gate).
`AdminEntryDetailController` (`GET /api/admin/entries/{id}`): liefert `EntrySerializer::toDetail($entry, $versions->findBy(['entry'=>$entry]))` + offene Reports.
`ReportListController` (`GET /api/admin/reports`): `reports->findBy(['status'=>ReportStatus::Open])` serialisiert.
`ReportResolveController` (`POST /api/admin/reports/{id}/resolve`, Body `{publish: bool}`): `moderation->resolveReport($report, (bool)$publish)` + Audit `report.resolve`.
`SubmitterBanController` (`/ban`, Step-up + Backup-Gate): `moderation->ban($submitter)` + Audit `submitter.ban`.
`SubmitterUnbanController` (`/unban`): `moderation->unban($submitter)` + Audit `submitter.unban` (kein Step-up laut Spec nur `ban`).

- [ ] **Step 5: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter ModerationTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Controller/Admin/*.php backend/tests/Functional/Admin/ModerationTest.php backend/tests/Functional/Admin/AdminTestCase.php
git commit -m "Ergänze Admin-Moderationsendpunkte mit Audit"
```

---

### Task 10: Nutzerverwaltung + Einladungs-E-Mail (nur `admin`)

**Files:**
- Create: `backend/config/packages/mailer.yaml`
- Create: `backend/src/Service/InviteMailer.php`
- Create: `backend/src/Controller/Admin/UserListController.php`, `UserInviteController.php`, `UserDisableController.php`, `UserReinviteController.php`
- Create: `backend/src/Controller/Admin/RegisterOptionsController.php`, `RegisterController.php`
- Test: `backend/tests/Functional/Admin/UserManagementTest.php`, `RegistrationTest.php`

**Interfaces:**
- Consumes: `InviteTokenService`, `AuditLogger`, `MailerInterface`, `WebAuthnCeremony`, Repos.
- Produces: Invite → E-Mail (Test-Transport) → Registrierung (erster Passkey) macht Account `active`.

- [ ] **Step 1: `mailer.yaml`** + Test-Transport

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```
`.env`: `MAILER_DSN=null://null` (lokal/Default). `when@test` nutzt automatisch den in-memory-Transport von `symfony/mailer` (Assertions via `MailerAssertionsTrait`).

- [ ] **Step 2: Failing test des Invite→E-Mail→Registrierungs-Flows**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class UserManagementTest extends AdminTestCase
{
    use MailerAssertionsTrait;

    public function testInviteSendsEmailAndCreatesInvitedUser(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'Neu', 'email' => 'neu@example.com', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);

        $invited = $this->em->getRepository(\App\Entity\AdminUser::class)->findOneBy(['email' => 'neu@example.com']);
        self::assertSame(AdminUserStatus::Invited, $invited->status);
    }

    public function testModeratorCannotInvite(): void
    {
        $mod = $this->createAdmin('mod@example.com', AdminRole::Moderator);
        $this->loginWithCredentials($mod);
        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'X', 'email' => 'x@example.com', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 3: Rot laufen lassen**

Run: `php backend/bin/phpunit --filter UserManagementTest; echo $?` → FAIL.

- [ ] **Step 4: `InviteMailer` + Controller implementieren**

`InviteMailer` verschickt eine schlichte deutsche Text-Mail mit dem Registrierungslink (`https://gestura.eu/admin/register?token=<klartext>`):
```php
<?php
declare(strict_types=1);
namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class InviteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:default_from:MAILER_FROM)%')] private readonly string $from = 'admin@gestura.eu',
    ) {}

    public function send(string $to, string $token, \DateTimeImmutable $expiresAt): void
    {
        $link = 'https://gestura.eu/admin/register?token=' . rawurlencode($token);
        $body = "Du wurdest ins Gestura-Index-Admin eingeladen.\n\n"
            . "Registrierung (Passkey anlegen):\n{$link}\n\n"
            . 'Der Link läuft ab am ' . $expiresAt->format('d.m.Y H:i') . " Uhr.\n";
        $this->mailer->send((new Email())->from($this->from)->to($to)->subject('Einladung ins Gestura-Index-Admin')->text($body));
    }
}
```
`UserInviteController` (`POST /api/admin/users`, Step-up + Backup-Gate, nur admin via access_control): legt `invited`-`AdminUser` + `AdminInvite` (72 h) an, verschickt Mail, Audit `user.invite`:
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\InviteMailer;
use App\Service\InviteTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class UserInviteController
{
    #[Route('/api/admin/users', methods: ['POST'])]
    public function __invoke(
        Request $request, Security $security, AdminUserRepository $users, EntityManagerInterface $em,
        InviteTokenService $tokens, InviteMailer $mailer, AuditLogger $audit,
        StepUpGuard $stepUp, BackupPasskeyGate $backup,
    ): JsonResponse {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $email = $body['email'] ?? null;
        $displayName = $body['displayName'] ?? null;
        $role = AdminRole::tryFrom((string) ($body['role'] ?? '')) ?? throw new ApiProblem(400, 'Invalid role');
        if (!is_string($email) || !is_string($displayName) || $email === '' || $displayName === '') {
            throw new ApiProblem(400, 'displayName and email are required');
        }
        if ($users->findOneByEmail($email) !== null) {
            throw new ApiProblem(409, 'Email already exists');
        }
        $user = new AdminUser($displayName, $email, $role);
        $em->persist($user);
        $gen = $tokens->generate();
        $expiresAt = new \DateTimeImmutable('+72 hours');
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, $role, $expiresAt);
        $invite->createdBy = $actor;
        $em->persist($invite);
        $em->flush();
        $mailer->send($email, $gen->token, $expiresAt);
        $audit->log($actor, 'user.invite', 'admin_user', (string) $user->id, ['role' => $role->value]);
        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'status' => $user->status->value], 201);
    }
}
```
`UserListController` (`GET /api/admin/users`), `UserDisableController` (`POST /api/admin/users/{id}/disable`, Step-up, Audit `user.disable`, setzt `status = Disabled`), `UserReinviteController` (`POST /api/admin/users/{id}/reinvite`, neuer `AdminInvite` + Mail, Audit `user.reinvite`) analog.

`RegisterOptionsController` (`POST /api/admin/register/options`, Body `{token}`, PUBLIC) und `RegisterController` (`POST /api/admin/register`, Body enthält Token + Attestation): Token via `InviteTokenService::parse` → `AdminInviteRepository::findOneBySelector` → `verify` → Ablauf/`usedAt` prüfen → `WebAuthnCeremony::verifyRegistration($invite->adminUser, ...)` → Account `active`, `usedAt=now`, Audit `user.register`. Beispiel `RegisterController`:
```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminInviteRepository;
use App\Service\AuditLogger;
use App\Service\InviteTokenService;
use App\Service\RateLimitGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController
{
    #[Route('/api/admin/register', methods: ['POST'])]
    public function __invoke(
        Request $request, InviteTokenService $tokens, AdminInviteRepository $invites,
        WebAuthnCeremony $ceremony, EntityManagerInterface $em, AuditLogger $audit,
        RateLimitGuard $guard, RateLimiterFactoryInterface $adminRegisterLimiter,
    ): JsonResponse {
        $guard->consume($adminRegisterLimiter, $request->getClientIp() ?? 'unknown');
        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $parsed = $tokens->parse((string) ($body['token'] ?? '')) ?? throw new ApiProblem(400, 'Invalid token');
        $invite = $invites->findOneBySelector($parsed['selector']) ?? throw new ApiProblem(404, 'Invite not found');
        if ($invite->usedAt !== null || $invite->expiresAt < new \DateTimeImmutable() || !$tokens->verify($parsed['verifier'], $invite->tokenHash)) {
            throw new ApiProblem(400, 'Invite is invalid or expired');
        }
        $attestationJson = json_encode($body['attestation'] ?? $body, JSON_THROW_ON_ERROR);
        $ceremony->verifyRegistration($invite->adminUser, $attestationJson, 'Erster Passkey');
        $invite->adminUser->status = AdminUserStatus::Active;
        $invite->usedAt = new \DateTimeImmutable();
        $em->flush();
        $audit->log($invite->adminUser, 'user.register', 'admin_user', (string) $invite->adminUser->id);
        return new JsonResponse(['status' => 'active'], 201);
    }
}
```

- [ ] **Step 5: Registrierungs-Test**

`RegistrationTest`: Invite direkt anlegen (Token + Hash über `InviteTokenService`), `POST /api/admin/register` mit gültigem Token + Fake-Attestation (`{"id":"cred-x"}`) → 201, Account `active`; abgelaufenes/verbrauchtes Token → 400.

- [ ] **Step 6: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter UserManagementTest; php backend/bin/phpunit --filter RegistrationTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/config/packages/mailer.yaml backend/src/Service/InviteMailer.php backend/src/Controller/Admin/User*.php backend/src/Controller/Admin/Register*.php backend/tests/Functional/Admin/UserManagementTest.php backend/tests/Functional/Admin/RegistrationTest.php
git commit -m "Ergänze Nutzerverwaltung und Einladungs-Flow"
```

---

### Task 11: Audit-Log-Endpunkt (nur `admin`)

**Files:**
- Create: `backend/src/Controller/Admin/AuditListController.php`
- Test: `backend/tests/Functional/Admin/AuditTest.php`

- [ ] **Step 1: Failing test** (Moderator → 403; Admin → 200 mit Einträgen)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Enum\AdminRole;

final class AuditTest extends AdminTestCase
{
    public function testAdminSeesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // erzeugt auth.login-Audit
        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('items', $this->json());
    }

    public function testModeratorForbidden(): void
    {
        $mod = $this->createAdmin('mod@example.com', AdminRole::Moderator);
        $this->loginWithCredentials($mod);
        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: Rot → implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Repository\AuditLogEntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AuditListController
{
    #[Route('/api/admin/audit', methods: ['GET'])]
    public function __invoke(Request $request, AuditLogEntryRepository $repo): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', '50')));
        $items = [];
        foreach ($repo->page($page, $perPage) as $a) {
            $items[] = [
                'id' => $a->id,
                'actor' => $a->actor?->email,
                'action' => $a->action,
                'targetType' => $a->targetType,
                'targetId' => $a->targetId,
                'detail' => $a->detail,
                'createdAt' => $a->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
        return new JsonResponse(['items' => $items, 'page' => $page, 'perPage' => $perPage]);
    }
}
```

- [ ] **Step 3: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter AuditTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Controller/Admin/AuditListController.php backend/tests/Functional/Admin/AuditTest.php
git commit -m "Ergänze Audit-Log-Endpunkt"
```

---

### Task 12: CORS-Differenzierung, CSRF-Header, Rate-Limiter

**Files:**
- Modify: `backend/src/EventSubscriber/CorsSubscriber.php`
- Create: `backend/src/EventSubscriber/AdminCsrfSubscriber.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Functional/Admin/CorsCsrfTest.php`
- Test: `backend/tests/Unit/AdminLoginThrottleTest.php`

- [ ] **Step 1: Failing test** (Admin-CORS mit Credentials + fester Origin; fehlender CSRF-Header → 403)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

final class CorsCsrfTest extends AdminTestCase
{
    public function testAdminCorsIsCredentialed(): void
    {
        $this->client->request('OPTIONS', '/api/admin/auth/options', server: ['HTTP_ORIGIN' => 'https://gestura.eu']);
        $r = $this->client->getResponse();
        self::assertSame('https://gestura.eu', $r->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $r->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testPublicCorsStaysWildcard(): void
    {
        $this->client->request('OPTIONS', '/api/v1/entries', server: ['HTTP_ORIGIN' => 'https://example.org']);
        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testStateChangingAdminNeedsCsrfHeader(): void
    {
        $this->client->request('POST', '/api/admin/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'], // KEIN X-Requested-With
            content: json_encode(['id' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: `CorsSubscriber` differenzieren**

`corsHeaders()` bekommt den Request bzw. Pfad+Origin; für `^/api/admin` feste Origin `https://gestura.eu` + Credentials + `X-Requested-With` in den erlaubten Headern:
```php
private function corsHeaders(Request $request): array
{
    $path = $request->getPathInfo();
    if (str_starts_with($path, '/api/admin')) {
        return [
            'Access-Control-Allow-Origin' => 'https://gestura.eu',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];
    }
    return [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
        'Access-Control-Max-Age' => '86400',
    ];
}
```
Aufrufer (`onKernelRequest`/`onKernelResponse`) `$this->corsHeaders($request)` übergeben.

- [ ] **Step 3: `AdminCsrfSubscriber`** (zustandsändernde Methoden brauchen `X-Requested-With`)

```php
<?php
declare(strict_types=1);
namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AdminCsrfSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 200]]; // nach CORS (256), vor Router
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->isMainRequest()) {
            return;
        }
        if (!str_starts_with($request->getPathInfo(), '/api/admin')) {
            return;
        }
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            throw new ApiProblem(403, 'Missing X-Requested-With header');
        }
    }
}
```

- [ ] **Step 4: Rate-Limiter ergänzen** (`rate_limiter.yaml`, beide Blöcke)

```yaml
# im framework.rate_limiter-Block:
        admin_login:    { policy: sliding_window, limit: 10, interval: '15 minutes' }
        admin_register: { policy: sliding_window, limit: 10, interval: '1 hour' }
        admin_invite:   { policy: sliding_window, limit: 20, interval: '1 hour' }
# im when@test.framework.rate_limiter-Block:
        admin_login:    { policy: sliding_window, limit: 1000, interval: '15 minutes' }
        admin_register: { policy: sliding_window, limit: 1000, interval: '1 hour' }
        admin_invite:   { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 5: Isolierter Drosselungstest** (echtes Limit via `InMemoryStorage`, Muster wie `RateLimitGuardTest`)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AdminLoginThrottleTest extends TestCase
{
    public function testLoginThrottleTripsAfterLimit(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'admin_login', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();
        for ($i = 0; $i < 3; $i++) {
            $guard->consume($factory, '10.0.0.9');
        }
        $this->expectException(ApiProblem::class);
        $guard->consume($factory, '10.0.0.9');
    }
}
```

- [ ] **Step 6: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter CorsCsrfTest; php backend/bin/phpunit --filter AdminLoginThrottleTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/EventSubscriber/CorsSubscriber.php backend/src/EventSubscriber/AdminCsrfSubscriber.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/Admin/CorsCsrfTest.php backend/tests/Unit/AdminLoginThrottleTest.php
git commit -m "Ergänze credentialed CORS, CSRF-Header und Admin-Limiter"
```

---

### Task 13: Bootstrap-CLI `index:admin:create`

**Files:**
- Create: `backend/src/Command/AdminCreateCommand.php`
- Test: `backend/tests/Command/AdminCreateCommandTest.php`

**Interfaces:**
- Consumes: `AdminUserRepository`, `InviteTokenService`, `InviteMailer`, `AuditLogger`, `EntityManagerInterface`.
- Produces: legt `invited`-Admin + `AdminInvite` an, verschickt Mail, druckt den Link.

- [ ] **Step 1: Failing test** (Command legt invited-Admin an + druckt Link)

```php
<?php
declare(strict_types=1);
namespace App\Tests\Command;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminCreateCommandTest extends KernelTestCase
{
    public function testCreatesInvitedAdmin(): void
    {
        self::bootKernel();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $app = new Application(static::$kernel);
        $tester = new CommandTester($app->find('index:admin:create'));
        $tester->execute(['displayName' => 'Chef', 'email' => 'boot@example.com', '--role' => 'admin']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('gsta_', $tester->getDisplay());

        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $u = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'boot@example.com']);
        self::assertSame(AdminUserStatus::Invited, $u->status);
    }
}
```

- [ ] **Step 2: Rot → implementieren**

```php
<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Repository\AdminUserRepository;
use App\Service\AuditLogger;
use App\Service\InviteMailer;
use App\Service\InviteTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'index:admin:create', description: 'Legt einen eingeladenen Admin an und verschickt die Einladung')]
final class AdminCreateCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly InviteTokenService $tokens,
        private readonly InviteMailer $mailer,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('displayName', InputArgument::REQUIRED)
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin|moderator', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        if ($this->users->findOneByEmail($email) !== null) {
            $output->writeln('<error>E-Mail existiert bereits.</error>');
            return Command::FAILURE;
        }
        $role = AdminRole::tryFrom((string) $input->getOption('role'));
        if ($role === null) {
            $output->writeln('<error>Ungültige Rolle.</error>');
            return Command::FAILURE;
        }
        $user = new AdminUser((string) $input->getArgument('displayName'), $email, $role);
        $this->em->persist($user);
        $gen = $this->tokens->generate();
        $expiresAt = new \DateTimeImmutable('+72 hours');
        $this->em->persist(new AdminInvite($gen->selector, $gen->hash, $user, $role, $expiresAt));
        $this->em->flush();
        $this->mailer->send($email, $gen->token, $expiresAt);
        $this->audit->log(null, 'user.invite', 'admin_user', (string) $user->id, ['role' => $role->value, 'via' => 'cli']);
        $output->writeln('Einladung verschickt. Fallback-Link:');
        $output->writeln('https://gestura.eu/admin/register?token=' . $gen->token);
        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Grün + Volltest + Commit**

Run: `php backend/bin/phpunit --filter AdminCreateCommandTest; echo $?` → PASS.
Run: `php backend/bin/phpunit; echo $?` → OK, `0`.
```bash
git add backend/src/Command/AdminCreateCommand.php backend/tests/Command/AdminCreateCommandTest.php
git commit -m "Ergänze Bootstrap-CLI für den ersten Admin"
```

---

### Task 14: Deploy-Notizen + Spec-Abgleich (Abschluss)

**Files:**
- Modify: `deploy/README.md` (neue `.env.local`-Variablen)
- Modify: `.claude/lessons.md` (neue Fallen)

- [ ] **Step 1: `.env`-Defaults dokumentieren/ergänzen**

`backend/.env` (Repo, Defaults): `MAILER_DSN=null://null`, `WEBAUTHN_RP_ID=gestura.eu` (bzw. leer → Parameter-Default), `SESSION_COOKIE_DOMAIN=` (leer). Serverseitig in `.env.local` (nie im Repo): `MAILER_DSN=<SMTP des Hosters>`, `SESSION_COOKIE_DOMAIN=.gestura.eu`, `WEBAUTHN_RP_ID=gestura.eu`, `MAILER_FROM=admin@gestura.eu`.

- [ ] **Step 2: `deploy/README.md` erweitern** — Abschnitt »Admin-Backend (SP4a)«: neue `.env.local`-Variablen, Hinweis dass Migrationen die vier Admin-Tabellen anlegen, und der einmalige Bootstrap nach Deploy:
```bash
php85 bin/console index:admin:create "Dein Name" deine@mail.tld --role=admin
```

- [ ] **Step 3: `.claude/lessons.md`** — je eine Zeile zu: (a) WebAuthn-API ist versions-empfindlich, Typen gegen `vendor/` prüfen; (b) Admin-Firewall liest eigenes Session-Cookie über `AdminSessionAuthenticator` (kein Bundle-Firewall-Authenticator); (c) Ceremony im Test über `FakeWebAuthnCeremony`-Alias in `when@test`; (d) destruktive Admin-Aktionen brauchen Step-up **und** Backup-Passkey-Gate.

- [ ] **Step 4: Voller Abschluss-Testlauf**

Run: `php backend/bin/phpunit; echo $?`
Expected: `OK`, Exit-Code `0`.

- [ ] **Step 5: Commit**

```bash
git add deploy/README.md .claude/lessons.md backend/.env
git commit -m "Dokumentiere Admin-Deploy und ergänze Lessons"
```

---

## Self-Review (gegen die Spec)

- **Auth-Modell (Spec §3):** UV=required (webauthn.yaml Profile), RP-ID gestura.eu (Task 6), httpOnly/SameSite=Strict/Secure/Domain/30-min (Task 5), Step-up <5 min (Task 7 `StepUpGuard`), Bibliothek `web-auth/webauthn-symfony-bundle` (Task 1, Hybrid-Nutzung), Backup-Passkey ≥ 2 (Task 8 Gate + Remove-409). ✓
- **Rollen (Spec §4):** `AdminRole` (Task 2), `#[IsGranted]`/access_control (Task 5), Moderator-403 auf Verwaltung/Audit (Tasks 10/11). ✓
- **Datenmodell (Spec §5):** vier Entities + Enums (Task 3). `WebAuthnCredential` speichert `source` (serialisierter Record) statt Einzelfelder — bewusste, robuste Abweichung; `signatureCounter`/`aaguid` stecken darin (`aaguid` zusätzlich als Spalte). ✓
- **Provisionierung (Spec §6):** CLI (Task 13), Invite-Endpoint + Mail (Task 10), Registrierung → active + usedAt (Task 10), Reinvite (Task 10), Mailer/`MAILER_DSN` (Tasks 10/14). ✓
- **Endpunkte (Spec §7):** Auth/Session (Task 7), Passkey-Verwaltung (Task 8), Moderation (Task 9), Verwaltung + Audit (Tasks 10/11). ✓
- **Sicherheit (Spec §8):** Firewall (Task 5), credentialed CORS (Task 12), Rate-Limiter (Task 12), Step-up serverseitig (Task 7), Backup-Gate 409 (Tasks 8/9/10), CSRF via X-Requested-With (Task 12). ✓
- **Tests (Spec §9):** Ceremonies gemockt (Task 6 Fake), Rollen-Guards (Tasks 10/11), Moderation end-to-end + Audit (Task 9), Invite-Flow + Test-Transport (Task 10), Session-401/Step-up (Tasks 5/7), Backup-Pflicht (Task 8), `failOnDeprecation` (durchgängig `echo $?`). ✓
- **Deployment (Spec §10):** Migrationen (Task 3), security.yaml/webauthn.yaml (Tasks 5/6), `.env.local`-Variablen + Bootstrap (Task 14). ✓

**Offene Verifikationspunkte für die Umsetzung (bewusst, weil versions-empfindlich):** exakte WebAuthn-5.x-Klassen-/Service-/Config-Namen (Task 1 Step 3 + Task 6 Hinweise). Alles andere ist vollständig ausgeführt.
