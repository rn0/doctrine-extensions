<?php

/**
 * (c) FSi sp. z o.o. <info@fsi.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace FSi\DoctrineExtensions\Translatable\Entity\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder as BaseQueryBuilder;
use Doctrine\ORM\Query\Expr;
use FSi\DoctrineExtensions\Translatable\Exception\RuntimeException;
use FSi\DoctrineExtensions\Translatable\Mapping\ClassMetadata as TranslatableMetadata;
use FSi\DoctrineExtensions\Translatable\Model\TranslatableRepositoryInterface;
use FSi\DoctrineExtensions\Translatable\Query\QueryBuilder;
use FSi\DoctrineExtensions\Translatable\TranslatableListener;

class TranslatableRepository extends EntityRepository implements TranslatableRepositoryInterface
{
    /**
     * @var TranslatableListener
     */
    protected $listener;

    /**
     * @var TranslatableMetadata
     */
    protected $extendedMetadata;

    /**
     * @var ClassMetadata[]
     */
    protected $translationMetadata;

    /**
     * @var TranslatableMetadata[]
     */
    protected $translationExtendedMetadata;

    public function findTranslatableBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $locale = null
    ) {
        return $this->createFindTranslatableQueryBuilder(
            'e',
            $criteria,
            $orderBy,
            $limit,
            $offset,
            $locale
        )->getQuery()->execute();
    }

    public function findTranslatableOneBy(
        array $criteria,
        ?array $orderBy = null,
        ?string $locale = null
    ) {
        return $this->createFindTranslatableQueryBuilder(
            'e',
            $criteria,
            $orderBy,
            1,
            null,
            $locale
        )->getQuery()->getSingleResult();
    }

    public function createTranslatableQueryBuilder(
        string $alias,
        string $translationAlias = 't',
        string $defaultTranslationAlias = 'dt'
    ): BaseQueryBuilder {
        $qb = new QueryBuilder($this->getEntityManager());
        $qb->select($alias)->from($this->getEntityName(), $alias);

        $translatableProperties = $this->getExtendedMetadata()->getTranslatableProperties();
        foreach (array_keys($translatableProperties) as $translationAssociation) {
            $join = sprintf('%s.%s', $alias, $translationAssociation);
            $qb->joinAndSelectCurrentTranslations(
                $join, Expr\Join::LEFT_JOIN,
                $translationAlias,
                'locale'
            );
            $qb->joinAndSelectDefaultTranslations(
                $join, Expr\Join::LEFT_JOIN,
                $defaultTranslationAlias,
                'deflocale'
            );
        }

        return $qb;
    }

    public function hasTranslation(
        $object,
        string $locale,
        string $translationAssociation = 'translations'
    ): bool {
        return null !== $this->findTranslation(
            $object,
            $locale,
            $translationAssociation
        );
    }

    public function getTranslation(
        $object,
        string $locale,
        string $translationAssociation = 'translations'
    ) {
        $this->validateObject($object);
        $this->validateTranslationAssociation($translationAssociation);

        $translation = $this->findTranslation(
            $object,
            $locale,
            $translationAssociation
        );

        if (null !== $translation) {
            return $translation;
        }

        return $this->createTranslation(
            $object,
            $translationAssociation,
            $locale
        );
    }

    public function findTranslation(
        $object,
        string $locale,
        string $translationAssociation = 'translations'
    ) {
        $this->validateObject($object);
        $this->validateTranslationAssociation($translationAssociation);

        if ($this->areTranslationsIndexedByLocale($translationAssociation)) {
            return $this->getTranslations($object, $translationAssociation)->get($locale);
        }

        return $this->findNonIndexedTranslation(
            $object,
            $translationAssociation,
            $locale
        );
    }

    /**
     * @param object $object
     * @param string|null $translationAssociation
     * @return Collection
     * @throws RuntimeException
     */
    public function getTranslations(
        $object,
        string $translationAssociation = 'translations'
    ): Collection {
        $translations = $this->getClassMetadata()->getFieldValue($object, $translationAssociation);

        if ($translations === null) {
            return new ArrayCollection();
        }

        if (!($translations instanceof Collection)) {
            throw new RuntimeException(sprintf(
                'Entity %s must contains implementation of "%s" in "%s" association',
                $this->getClassName(),
                Collection::class,
                $translationAssociation
            ));
        }

        return $translations;
    }

    protected function findNonIndexedTranslation(
        $object,
        string $translationAssociation,
        string $locale
    ) {
        $translations = $this->getTranslations($object, $translationAssociation);
        foreach ($translations as $translation) {
            $translationLocale = $this->getTranslationLocale(
                $translationAssociation,
                $translation
            );
            if ($translationLocale === $locale) {
                return $translation;
            }
        }

        return null;
    }

    protected function createTranslation(
        $object,
        string $translationAssociation,
        string $locale
    ) {
        $translation = $this->getTranslationMetadata($translationAssociation)
            ->getReflectionClass()
            ->newInstance()
        ;

        $this->setTranslationObject($translationAssociation, $translation, $object);
        $this->setTranslationLocale($translationAssociation, $translation, $locale);

        if ($this->areTranslationsIndexedByLocale($translationAssociation)) {
            $this->getTranslations($object, $translationAssociation)->set($locale, $translation);
        } else {
            $this->getTranslations($object, $translationAssociation)->add($translation);
        }

        return $translation;
    }

    /**
     * @return TranslatableListener
     * @throws RuntimeException
     */
    protected function getTranslatableListener(): TranslatableListener
    {
        if (!$this->listener) {
            $evm = $this->getEntityManager()->getEventManager();
            foreach ($evm->getListeners() as $listeners) {
                foreach ($listeners as $listener) {
                    if ($listener instanceof TranslatableListener) {
                        $this->listener = $listener;
                    }
                }
            }
        }

        if (null === $this->listener) {
            throw new RuntimeException('Cannot find TranslatableListener in EntityManager\'s EventManager');
        }

        return $this->listener;
    }

    protected function getExtendedMetadata(): TranslatableMetadata
    {
        if (!isset($this->extendedMetadata)) {
            $this->extendedMetadata = $this->getTranslatableListener()->getExtendedMetadata(
                $this->getEntityManager(),
                $this->getClassName()
            );
        }

        return $this->extendedMetadata;
    }

    protected function getTranslationMetadata(string $translationAssociation): ClassMetadata
    {
        if (!isset($this->translationMetadata[$translationAssociation])) {
            $this->translationMetadata[$translationAssociation] =
                $this->getEntityManager()->getClassMetadata(
                    $this->getClassMetadata()->getAssociationTargetClass(
                        $translationAssociation
                    )
                );
        }

        return $this->translationMetadata[$translationAssociation];
    }

    protected function getTranslationExtendedMetadata(string $association): TranslatableMetadata
    {
        if (!isset($this->translationExtendedMetadata[$association])) {
            $this->translationExtendedMetadata[$association] =
                $this->getTranslatableListener()->getExtendedMetadata(
                    $this->getEntityManager(),
                    $this->getClassMetadata()->getAssociationTargetClass($association)
                );
        }

        return $this->translationExtendedMetadata[$association];
    }

    /**
     * @param object $object
     * @return void
     * @throws RuntimeException
     */
    protected function validateObject($object): void
    {
        $className = $this->getClassName();
        if (!($object instanceof $className)) {
            throw new RuntimeException(sprintf(
                'Expected entity of class %s, but got %s',
                $className,
                is_object($object) ? get_class($object) : gettype($object)
            ));
        }
    }

    /**
     * @param string $translationAssociation
     * @return void
     * @throws RuntimeException
     */
    protected function validateTranslationAssociation(string $translationAssociation): void
    {
        $translatableProperties = $this->getExtendedMetadata()->getTranslatableProperties();

        if (!isset($translatableProperties[$translationAssociation])) {
            throw new RuntimeException(sprintf(
                'Entity %s has no translations association named %s',
                $this->getClassName(),
                $translationAssociation
            ));
        }
    }

    protected function areTranslationsIndexedByLocale(string $translationAssociation): bool
    {
        $translationAssociationMapping = $this->getClassMetadata()
            ->getAssociationMapping($translationAssociation)
        ;
        if (!isset($translationAssociationMapping['indexBy'])) {
            return false;
        }

        $translationExtendedMeta = $this->getTranslationExtendedMetadata($translationAssociation);
        return $translationAssociationMapping['indexBy'] == $translationExtendedMeta->localeProperty;
    }

    /**
     * @param string $translationAssociation
     * @param object $translation
     * @return string|null
     */
    protected function getTranslationLocale(
        string $translationAssociation,
        $translation
    ): ?string {
        return $this->getTranslationMetadata($translationAssociation)->getFieldValue(
            $translation,
            $this->getTranslationExtendedMetadata($translationAssociation)->localeProperty
        );
    }

    /**
     * @param string $translationAssociation
     * @param object $translation
     * @param string|null $locale
     * @return void
     */
    protected function setTranslationLocale(
        string $translationAssociation,
        $translation,
        ?string $locale
    ): void {
        $this->getTranslationMetadata($translationAssociation)->setFieldValue(
            $translation,
            $this->getTranslationExtendedMetadata($translationAssociation)->localeProperty,
            $locale
        );
    }

    /**
     * @param string $translationAssociation
     * @param object $translation
     * @param object $object
     * @return void
     */
    protected function setTranslationObject(
        string $translationAssociation,
        $translation,
        $object
    ): void {
        $translationAssociationMapping = $this->getClassMetadata()->getAssociationMapping(
            $translationAssociation
        );
        $this->getTranslationMetadata($translationAssociation)->setFieldValue(
            $translation,
            $translationAssociationMapping['mappedBy'],
            $object
        );
    }

    private function createFindTranslatableQueryBuilder(
        string $alias,
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $locale = null
    ): BaseQueryBuilder {
        $qb = new QueryBuilder($this->getEntityManager());
        $qb->select($alias)->from($this->getEntityName(), $alias);

        foreach ($criteria as $criteriaField => $criteriaValue) {
            $qb->addTranslatableWhere($alias, $criteriaField, $criteriaValue, $locale);
        }

        if (!is_null($orderBy)) {
            foreach ($orderBy as $orderField => $orderDirection) {
                $qb->addTranslatableOrderBy($alias, $orderField, $orderDirection, $locale);
            }
        }

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }
        if (null !== $offset) {
            return $qb->setFirstResult($offset);
        }

        return $qb;
    }
}
