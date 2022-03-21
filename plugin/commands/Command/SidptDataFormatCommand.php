<?php

namespace Sidpt\CommandsBundle\Command;

use Claroline\AppBundle\API\Crud;
use Claroline\AppBundle\API\FinderProvider;
use Claroline\AppBundle\API\SerializerProvider;
use Claroline\AppBundle\Persistence\ObjectManager;
use Claroline\CoreBundle\Entity\DataSource;
use Claroline\CoreBundle\Entity\Resource\ResourceNode;
use Claroline\CoreBundle\Entity\Resource\ResourceType;
use Claroline\CoreBundle\Entity\Widget\Type\ListWidget;
use Claroline\CoreBundle\Entity\Widget\Type\ResourceWidget;
use Claroline\CoreBundle\Entity\Widget\Widget;
use Claroline\CoreBundle\Entity\Widget\WidgetContainer;
use Claroline\CoreBundle\Entity\Widget\WidgetContainerConfig;
use Claroline\CoreBundle\Entity\Widget\WidgetInstance;

// entities
use Claroline\CoreBundle\Entity\Widget\WidgetInstanceConfig;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Manager\ContentTranslationManager;
use Claroline\CoreBundle\Manager\Organization\OrganizationManager;
use Claroline\CoreBundle\Manager\ResourceManager;
use Claroline\CoreBundle\Manager\RoleManager;
use Claroline\CoreBundle\Manager\Workspace\WorkspaceManager;
use Claroline\HomeBundle\Entity\HomeTab;
use Claroline\TagBundle\Manager\TagManager;
use Sidpt\BinderBundle\Entity\Binder;
use Sidpt\BinderBundle\Entity\Document;
use Sidpt\BinderBundle\API\Manager\DocumentManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UJM\ExoBundle\Library\Options\ExerciseType;

/**
 * Set/update the hierarchy layout
 */
class SidptDataFormatCommand extends Command
{

    // Class parameters
    private $om;
    private $crud;
    private $serializer;
    private $finder;
    private $organizationManager;
    private $tagManager;
    private $roleManager;
    private $workspaceManager;
    private $resourceManager;

    // Class variables for execute function (hoping it can be help performances)
    private $workspaceRepo;
    private $resourceNodeRepo;
    private $homeTabsRepo;
    private $resourceWidgetsRepo;
    private $listWidgetsRepo;

    private $binderType;
    private $documentType;
    private $directoryType;
    private $lessonType;
    private $exerciseType;
    private $textType;
    private $resourceDataSource;
    private $resourcesListDataSource;
    private $resourceWidgetType;
    private $listWidgetType;

    private $nodeSeralizer;

    // Tags hierarchy
    private $contentLevel;
    private $professionnalProfile;
    private $estimatedTime;
    private $includedResources;

    private $translations;
    private $documentManager;

    /**
     * Constructor
     *
     * @param ObjectManager $om Claroline object manage
     * @param SerializerProvider $serializer Claroline serializer generic interface
     * @param TagManager $tagManager Claroline tag manager
     * @param ResourceManager $resourceManager Claroline resource manager
     * @param DocumentManager $documentManager Sidpt document manager
     */
    public function __construct(
        ObjectManager $om,
        SerializerProvider $serializer,
        TagManager $tagManager,
        ResourceManager $resourceManager,
        DocumentManager $documentManager
    ) {
        $this->documentManager = $documentManager;
        $this->om = $om;
        $this->serializer = $serializer;
        $this->tagManager = $tagManager;
        $this->resourceManager = $resourceManager;

        $this->workspaceRepo = $this->om->getRepository(Workspace::class);
        $this->resourceNodeRepo = $this->om->getRepository(ResourceNode::class);
        $this->homeTabsRepo = $this->om->getRepository(HomeTab::class);
        $this->resourceWidgetsRepo = $this->om->getRepository(ResourceWidget::class);
        $this->listWidgetsRepo = $this->om->getRepository(ListWidget::class);

        $widgetsTypeRepo = $this->om->getRepository(Widget::class);
        $dataSourceRepo = $this->om->getRepository(DataSource::class);
        $typesRepo = $this->om->getRepository(ResourceType::class);

        $this->binderType = $typesRepo->findOneBy(
            ['name' => 'sidpt_binder']
        );
        $this->documentType = $typesRepo->findOneBy(
            ['name' => 'sidpt_document']
        );

        $this->directoryType = $typesRepo->findOneBy(
            ['name' => 'directory']
        );

        $this->lessonType = $typesRepo->findOneBy(
            ['name' => 'icap_lesson']
        );

        $this->exerciseType = $typesRepo->findOneBy(
            ['name' => 'ujm_exercise']
        );

        $this->textType = $typesRepo->findOneBy(
            ['name' => 'text']
        );

        $this->resourceDataSource = $dataSourceRepo->findOneBy(
            ['name' => 'resource']
        );
        $this->resourcesListDataSource = $dataSourceRepo->findOneBy(
            ['name' => 'resources']
        );

        $this->resourceWidgetType = $widgetsTypeRepo->findOneBy(
            ['name' => 'resource']
        );
        $this->listWidgetType = $widgetsTypeRepo->findOneBy(
            ['name' => 'list']
        );
        $this->nodeSeralizer = $this->serializer->get(ResourceNode::class);

        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Set or update the courses hierarchy for the SIDPT project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

      print("Memory - " . (memory_get_usage() / 1024) . " KB\r\n");
      gc_enable();
      $documents = $this->resourceNodeRepo->findBy(
          ['mimeType' => 'custom/sidpt_document']
      );
      $LUs = [];
      $LUsIds = [];
      $modules = [];
      $count = 0;
      // retrieve LU
      foreach ($documents as $key => $documentNode) {
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          if(!empty($document)){
            $docIsLU = $document->getShowDescription() &&
              $document->getShowOverview() &&
              $document->getWidgetsPagination();
            if($docIsLU){
              $LUs[$document->getId()] = $document;
              $LUsIds[] = $document->getId();
              $parent = $documentNode->getParent();
              if(!empty($parent) &&
                  $parent->getResourceType()->getName() == "sidpt_document"){
                $modules[$parent->getId()] = $parent;
              }
            }
          }
      }
      
      $this->om->flush();
      gc_collect_cycles();
      
      // batch update : https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/batch-processing.html
      $em = $this->om;
      $batchSize = 20;
      $i = 1;
      $q = $em->createQuery('select doc from Sidpt\BinderBundle\Entity\Document doc where doc.id in (:ids)');
      $q->setParameter('ids', $LUsIds);
      foreach ($q->toIterable() as $lu) {
          print("LU - " . $lu . "\r\n");
          $this->documentManager->configureAsLearningUnit($lu, false);
          ++$i;
          if (($i % $batchSize) === 0) {
              $em->flush(); // Executes all updates.
              $em->clear(); // Detaches all objects from Doctrine!
          }
      }
      $em->flush();


      $count = 0;
      $courses = [];
      foreach ($modules as $key => $documentNode) {
          print("Module - " . $documentNode->getName() . "\r\n");
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          
          $parent = $documentNode->getParent();
          if(!empty($parent) &&
            $parent->getResourceType()->getName() == "sidpt_document"
          ){
            $courses[$parent->getId()] = $parent;
          }
          $this->documentManager->configureAsModule($document, false);
          
          $count += 1;
          if ($count % 10 === 0) {
            $this->om->flush();
          }
      }
      $this->om->flush();

      $count = 0;

      foreach ($courses as $key => $documentNode) {
          print("Course - " . $documentNode->getName() . "\r\n");
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          
          $this->documentManager->configureAsCourse($document, false);
          $count += 1;
          if ($count % 10 === 0) {
            $this->om->flush();
          }
      }

      $this->om->flush();
      return 0;
    }


}
