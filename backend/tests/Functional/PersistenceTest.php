<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Repository\EntryVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersistenceTest extends KernelTestCase
{
    public function testEntryRoundTrip(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry('com.example.shop', EntryType::Menu, $submitter);
        $entry->setCategories([Category::Shopping, Category::Other]);
        $entry->tags = ['shop', 'beispiel'];
        $entry->domains = ['example.com'];
        $version = new EntryVersion($entry, '1.0.0', ['gesturaMenu' => 1, 'id' => 'com.example.shop'], str_repeat('a', 64));

        $em->persist($submitter);
        $em->persist($entry);
        $em->persist($version);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertNotNull($reloaded);
        self::assertSame(EntryStatus::Pending, $reloaded->status);
        self::assertEqualsCanonicalizing(['shopping', 'other'], $reloaded->categoryKeys());
        self::assertSame(['example.com'], $reloaded->domains);
    }

    public function testMaxSemverIsNullForEntryWithoutAnyVersion(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        // Bewusst OHNE zugehörige EntryVersion – im normalen Einreichungs-Fluss
        // gibt es das nicht, maxSemver() muss die Leere trotzdem robust behandeln.
        $entry = new Entry('com.example.ohneversion', EntryType::Menu, $submitter);

        $em->persist($submitter);
        $em->persist($entry);
        $em->flush();

        $repository = self::getContainer()->get(EntryVersionRepository::class);
        self::assertNull($repository->maxSemver($entry));
    }
}
