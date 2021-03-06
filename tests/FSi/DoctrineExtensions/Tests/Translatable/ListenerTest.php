<?php

/**
 * (c) FSi sp. z o.o. <info@fsi.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FSi\DoctrineExtensions\Tests\Translatable;

use DateTime;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\Article;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\ArticlePage;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\ArticleTranslation;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\Category;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\Comment;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\Section;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\TranslatableWithLocalelessTranslationTranslation;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\TranslatableWithoutLocale;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\TranslatableWithoutTranslations;
use FSi\DoctrineExtensions\Tests\Translatable\Fixture\TranslatableWithPersistentLocale;
use FSi\DoctrineExtensions\Translatable\Entity\Repository\TranslatableRepository;
use FSi\DoctrineExtensions\Translatable\Exception\MappingException;
use FSi\DoctrineExtensions\Translatable\Exception\RuntimeException;
use SplFileInfo;

class ListenerTest extends BaseTranslatableTest
{
    public const TEST_FILE1 = '/FSi/DoctrineExtensions/Tests/Uploadable/Fixture/penguins.jpg';
    public const TEST_FILE2 = '/FSi/DoctrineExtensions/Tests/Uploadable/Fixture/lighthouse.jpg';

    /**
     * Test simple entity creation with translation its state after $em->flush()
     */
    public function testInsert()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->logger->enabled = true;
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $this->entityManager->flush();

        $this->assertEquals(
            4,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_SUBTITLE,
            'subtitle',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test simple entity creation without translations and adding translation
     * later its state after $em->flush()
     */
    public function testInsertAndAddFirstTranslation()
    {
        $article = new Article();
        $article->setDate(new DateTime());
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setTitle(self::POLISH_TITLE_1);
        $article->setSubtitle(self::POLISH_SUBTITLE);
        $article->setContents(self::POLISH_CONTENTS_1);
        $article->setLocale(self::LANGUAGE_PL);
        $this->logger->enabled = true;
        $this->entityManager->flush();

        $this->assertEquals(
            3,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(
            1,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_SUBTITLE,
            'subtitle',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    public function testNotInsertTranslation()
    {
        $article = new Article();
        $article->setDate(new DateTime());
        $article->setLocale(self::LANGUAGE_PL);
        $this->entityManager->persist($article);
        $this->logger->enabled = true;
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $this->entityManager->flush();

        $this->assertEquals(
            3,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(0, $article->getTranslations()->count());
    }

    /**
     * Test simple entity creation with one translation and adding one later
     */
    public function testInsertAndAddTranslation()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $article->setTitle(self::ENGLISH_TITLE_1);
        $article->setSubtitle(self::ENGLISH_SUBTITLE);
        $article->setContents(self::ENGLISH_CONTENTS_1);
        $this->logger->enabled = true;
        $this->entityManager->flush();

        $this->assertEquals(
            3,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(
            2,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(
            self::ENGLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_EN)
        );

        $this->assertAttributeEquals(
            self::ENGLISH_SUBTITLE,
            'subtitle',
            $article->getTranslations()->get(self::LANGUAGE_EN)
        );

        $this->assertAttributeEquals(
            self::ENGLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_EN)
        );
    }

    /**
     * Test simple entity creation with two translation and check its state after $em->clear(), change default locale and load
     */
    public function testInsertWithTwoTranslationsClearAndLoad()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $article->setTitle(self::ENGLISH_TITLE_1);
        $article->setSubtitle(self::ENGLISH_SUBTITLE);
        $article->setContents(self::ENGLISH_CONTENTS_1);
        $this->entityManager->flush();

        $this->entityManager->clear();
        $this->logger->enabled = true;
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $this->assertEquals(
            5,
            count($this->logger->queries),
            'Reloading executed wrong number of queries'
        );

        $this->assertEquals(
            2,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(self::POLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_SUBTITLE, 'subtitle', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_1, 'contents', $article);

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_SUBTITLE,
            'subtitle',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test simple entity creation with two translations and removing one of them later
     */
    public function testInsertAndRemoveTranslation()
    {
        $article = new Article();
        $article->setDate(new DateTime());
        $article->setLocale(self::LANGUAGE_PL);
        $article->setTitle(self::POLISH_TITLE_1);
        $article->setContents(self::POLISH_CONTENTS_1);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $article->setTitle(self::ENGLISH_TITLE_1);
        $article->setContents(self::ENGLISH_CONTENTS_1);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->translatableListener->setLocale(self::LANGUAGE_EN);
        $article = $this->entityManager->find(Article::class, $article->getId());
        $article->setTitle(null);
        $article->setContents(null);

        $this->logger->enabled = true;
        $this->entityManager->flush();

        $this->assertEquals(
            4,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(
            1,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test simple entity creation with translation and reloading it after $em->clear()
     */
    public function testInsertClearAndLoad()
    {
        $article = $this->createArticle();
        $this->persistAndFlush($article);

        $this->logger->enabled = true;
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $this->assertEquals(
            5,
            count($this->logger->queries),
            'Reloading executed wrong number of queries'
        );

        $this->assertEquals(
            1,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(self::POLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_1, 'contents', $article);

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test updating previously created and persisted translation and its state after $em->flush()
     */
    public function testUpdate()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setTitle(self::POLISH_TITLE_2);
        $article->setContents(self::POLISH_CONTENTS_2);
        $this->logger->enabled = true;
        $this->entityManager->flush();

        $this->assertEquals(
            3,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(
            1,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(
            self::POLISH_TITLE_2,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_2,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test updating previously created and persisted translation and its state after $em->clear()
     */
    public function testUpdateClearAndLoad()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setTitle(self::POLISH_TITLE_2);
        $article->setContents(self::POLISH_CONTENTS_2);
        $this->entityManager->flush();

        $this->entityManager->clear();
        $this->logger->enabled = true;
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $this->assertEquals(
            5,
            count($this->logger->queries),
            'Reloading executed wrong number of queries'
        );

        $this->assertEquals(
            1,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(self::POLISH_TITLE_2, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_2, 'contents', $article);

        $this->assertAttributeEquals(
            self::POLISH_TITLE_2,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_2,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_PL)
        );
    }

    /**
     * Test copying one translation to another
     */
    public function testCopyTranslation()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $this->logger->enabled = true;
        $this->entityManager->flush();

        $this->assertEquals(
            3,
            count($this->logger->queries),
            'Flushing executed wrong number of queries'
        );

        $this->assertEquals(
            2,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(self::POLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_1, 'contents', $article);

        $this->assertAttributeEquals(
            self::POLISH_TITLE_1,
            'title',
            $article->getTranslations()->get(self::LANGUAGE_EN)
        );

        $this->assertAttributeEquals(
            self::POLISH_CONTENTS_1,
            'contents',
            $article->getTranslations()->get(self::LANGUAGE_EN)
        );
    }

    /**
     * Test entity creation with one translation in default language and check if that translation is loaded after changing language
     * to other
     */
    public function testLoadDefaultTranslation()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->translatableListener->setLocale(self::LANGUAGE_EN);
        $this->translatableListener->setDefaultLocale(self::LANGUAGE_PL);
        $this->logger->enabled = true;
        $article = $this->entityManager->find(Article::class, $article->getId());

        $this->assertEquals(
            5,
            count($this->logger->queries),
            'Reloading executed wrong number of queries'
        );

        $this->assertAttributeEquals(self::LANGUAGE_PL, 'locale', $article);
        $this->assertAttributeEquals(self::POLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_SUBTITLE, 'subtitle', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_1, 'contents', $article);

        $article->setLocale(self::LANGUAGE_EN);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $article = $this->entityManager->find(Article::class, $article->getId());
        $this->assertAttributeEquals(self::LANGUAGE_EN, 'locale', $article);
        $this->assertAttributeEquals(self::POLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::POLISH_CONTENTS_1, 'contents', $article);
    }

    /**
     * Assert that an empty string returned in Article::getLocale() will not mask
     * the fact that no locale was set for either the listener or the entity.
     */
    public function testCurrentLocaleSetToNull()
    {
        $this->translatableListener->setDefaultLocale(self::LANGUAGE_PL);
        $this->translatableListener->setLocale(null);

        $article = new Article();
        $article->setDate(new DateTime());
        $article->setLocale(null);
        $article->setTitle(self::POLISH_TITLE_1);
        $article->setContents(self::POLISH_CONTENTS_1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Neither object's locale nor the current locale was set for translatable properties"
        );

        $this->entityManager->persist($article);
        $this->entityManager->flush();
    }

    /**
     * Test entity creation with two translation and check its state after $em->clear(), change default locale and load with some
     * specific translation
     */
    public function testInsertWithTwoTranslationsClearAndLoadTranslation()
    {
        $article = $this->createArticle();
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $article->setTitle(self::ENGLISH_TITLE_1);
        $article->setContents(self::ENGLISH_CONTENTS_1);
        $this->entityManager->flush();

        $this->entityManager->clear();
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $this->logger->enabled = true;
        $this->translatableListener->loadTranslation($this->entityManager, $article, self::LANGUAGE_EN);

        $this->assertEquals(
            2,
            count($article->getTranslations()),
            'Number of translations is not valid'
        );

        $this->assertAttributeEquals(self::ENGLISH_TITLE_1, 'title', $article);
        $this->assertAttributeEquals(self::ENGLISH_CONTENTS_1, 'contents', $article);
    }

    /**
     * Test translatable and uploadable properties
     */
    public function testTranslatableUplodableProperties()
    {
        $article = $this->createArticle();
        $article->setIntroImage(new SplFileInfo(TESTS_PATH . self::TEST_FILE1));
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setLocale(self::LANGUAGE_EN);
        $article->setTitle(self::ENGLISH_TITLE_1);
        $article->setContents(self::ENGLISH_CONTENTS_1);
        $article->setIntroImage(new SplFileInfo(TESTS_PATH . self::TEST_FILE2));
        $this->entityManager->flush();

        $this->entityManager->clear();
        $this->translatableListener->setLocale(self::LANGUAGE_PL);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $file1 = $article->getIntroImage()->getKey();
        $this->assertFileExists(FILESYSTEM1 . $file1);

        $this->entityManager->clear();
        $this->translatableListener->setLocale(self::LANGUAGE_EN);
        $article = $this->entityManager->find(Article::class, $article->getId());

        $file2 = $article->getIntroImage()->getKey();
        $this->assertFileExists(FILESYSTEM1 . $file2);

        $this->assertNotSame($file1, $file2);
    }

    public function testPostHydrate()
    {
        $this->translatableListener->setLocale(self::LANGUAGE_EN);
        /* @var $repository TranslatableRepository */
        $repository = $this->entityManager->getRepository(Article::class);
        $article = new Article();
        $article->setDate(new DateTime());

        $translationEn = $repository->getTranslation($article, self::LANGUAGE_EN);
        $translationEn->setTitle(self::ENGLISH_TITLE_1);
        $translationEn->setContents(self::ENGLISH_CONTENTS_1);
        $translationPl = $repository->getTranslation($article, self::LANGUAGE_PL);
        $translationPl->setTitle(self::POLISH_TITLE_1);
        $translationPl->setContents(self::POLISH_CONTENTS_1);

        $this->entityManager->persist($translationEn);
        $this->entityManager->persist($translationPl);
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->logger->enabled = true;
        $query = $repository->createTranslatableQueryBuilder('a', 't', 'dt')->getQuery();

        $articles = $query->execute();
        foreach ($articles as $article) {
            $this->assertAttributeEquals(self::ENGLISH_TITLE_1, 'title', $article);
            $this->assertAttributeEquals(self::ENGLISH_CONTENTS_1, 'contents', $article);
        }

        $this->assertEquals(
            4,
            count($this->logger->queries),
            'Reloading executed wrong number of queries'
        );
    }

    public function testTranslatableWithoutLocaleProperty()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            "Entity '%s' has translatable properties so it must have property"
            . " marked with @Translatable\Language annotation",
            TranslatableWithoutLocale::class
        ));

        $this->translatableListener->getExtendedMetadata(
            $this->entityManager,
            TranslatableWithoutLocale::class
        );
    }

    public function testTranslatableWithoutTranslations()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            "Field 'translations' in entity '%s' has to be a OneToMany association",
            TranslatableWithoutTranslations::class
        ));

        $this->translatableListener->getExtendedMetadata(
            $this->entityManager,
            TranslatableWithoutTranslations::class
        );
    }

    public function testTranslatableWithPersistentLocale()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            "Entity '%s' seems to be a translatable entity so its 'locale' field"
            . " must not be persistent",
            TranslatableWithPersistentLocale::class
        ));

        $this->translatableListener->getExtendedMetadata(
            $this->entityManager,
            TranslatableWithPersistentLocale::class
        );
    }

    public function testTranslationsWithoutPersistentLocale()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            "Entity '%s' seems to be a translation entity so its 'locale' field must be persistent",
            TranslatableWithLocalelessTranslationTranslation::class
        ));

        $this->translatableListener->getExtendedMetadata(
            $this->entityManager,
            TranslatableWithLocalelessTranslationTranslation::class
        );
    }

    protected function getUsedEntityFixtures(): array
    {
        return [
            Category::class,
            Section::class,
            Comment::class,
            Article::class,
            ArticleTranslation::class,
            ArticlePage::class
        ];
    }
}
