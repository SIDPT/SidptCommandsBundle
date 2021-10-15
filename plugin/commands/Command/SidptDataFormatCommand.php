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
     *
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
        $this
            ->setDescription('Set or update the courses hierarchy for the SIDPT project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
      $documents = $this->resourceNodeRepo->findBy(
          ['mimeType' => 'custom/sidpt_document']
      );
      $modules = [];
      foreach ($documents as $key => $documentNode) {
          print("Document - " . $documentNode->getName() . "\r\n");
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          if(!empty($document)){
            $docIsLU = $document->getShowDescription() &&
              $document->getShowOverview() &&
              $document->getWidgetsPagination();
            if($docIsLU){
              $parent = $documentNode->getParent();
              if(!empty($parent) &&
                $parent->getResourceType()->getName() == "sidpt_document"
              ){
                $modules[$parent->getId()] = $parent;
              }
              $this->documentManager->configureAsLearningUnit($document, false);
            }
          }
      }

      $courses = [];
      foreach ($modules as $key => $documentNode) {
          print("Module - " . $documentNode->getName() . "\r\n");
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          //$docIsLU = $document->getShowDescription() &&
          //  $document->getShowOverview() &&
          //  $document->getWidgetsPagination();
          //if($docIsLU){
          $parent = $documentNode->getParent();
          if(!empty($parent) &&
            $parent->getResourceType()->getName() == "sidpt_document"
          ){
            $courses[$parent->getId()] = $parent;
          }
          $this->documentManager->configureAsModule($document, false);
          //}
      }

      foreach ($courses as $key => $documentNode) {
          print("Course - " . $documentNode->getName() . "\r\n");
          $document = $this->resourceManager->getResourceFromNode($documentNode);
          //$docIsLU = $document->getShowDescription() &&
          //  $document->getShowOverview() &&
          //  $document->getWidgetsPagination();
          //if($docIsLU){

          $this->documentManager->configureAsCourse($document, false);
          //}
      }

      $this->om->flush();
      return 0;
    }


}
