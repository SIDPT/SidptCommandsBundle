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

    /**
     *
     */
    public function __construct(
        ObjectManager $om,
        Crud $crud,
        SerializerProvider $serializer,
        FinderProvider $finder,
        OrganizationManager $organizationManager,
        TagManager $tagManager,
        RoleManager $roleManager,
        WorkspaceManager $workspaceManager,
        ResourceManager $resourceManager,
        ContentTranslationManager $translations
    ) {
        $this->translations = $translations;
        $this->om = $om;
        $this->crud = $crud;
        $this->serializer = $serializer;
        $this->finder = $finder;
        $this->organizationManager = $organizationManager;
        $this->tagManager = $tagManager;
        $this->roleManager = $roleManager;
        $this->workspaceManager = $workspaceManager;
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
        return $this->september2021Update2($input, $output);
    }

    /**
     * September update 2 :
     * - Learning units description now only contains the learning outcomes
     * - Overview is more customizable to possibly include
     *   - an intro message,
     *   - the description with a specific title
     *   - a disclaimer message
     *
     * @param  InputInterface  $input  [description]
     * @param  OutputInterface $output [description]
     * @return [type]                  [description]
     */
    protected function september2021Update2(InputInterface $input, OutputInterface $output): int
    {
        $documents = $this->resourceNodeRepo->findBy(
            ['mimeType' => 'custom/sidpt_document']
        );
        $moduleToUpdate = [];

        foreach ($documents as $key => $documentNode) {
            print("Document - " . $documentNode->getName() . "\r\n");
            $description = $documentNode->getDescription();
            $documentResource = $this->resourceManager
                ->getResourceFromNode($documentNode);

            $docIsLU = $documentResource != null ? $documentResource->getDescriptionTitle() == <<<HTML
<h3>{trans('Learning outcomes','clarodoc')}</h3>
HTML:false;
            $learningOutcomeContent = <<<HTML
                <p><span style="color: #ff0000;"><strong>Author, please fill the outcomes in the resource description</strong></span></p>
                HTML;
            // update previous description
            if (!empty($description)) {
                // original template
                $searchedOutcome = explode(
                    "<h3>Learning outcomes</h3>",
                    $description
                );
                if (count($searchedOutcome) > 1) {
                    $docIsLU = true;
                    $learningOutcomeContent = explode(
                        "<p>{{#resource.resourceNode.tags[\"Disclaimer\"] }}</p>",
                        $searchedOutcome[1]
                    )[0];
                    $learningOutcomeContent = trim($learningOutcomeContent);
                    $learningOutcomeContent = substr(
                        $learningOutcomeContent,
                        0,
                        strlen($learningOutcomeContent)
                    );
                } else {
                    // template v2
                    // (translations)
                    $searchedOutcome = explode(
                        "<h3>{trans('Learning outcome','clarodoc')}</h3>",
                        $description
                    );
                    if (count($searchedOutcome) > 1) {
                        $docIsLU = true;
                        $learningOutcomeContent = explode(
                            "<p id=\"disclaimer-start\">",
                            $searchedOutcome[1]
                        )[0];
                        $learningOutcomeContent = trim($learningOutcomeContent);
                    }
                }
            }
            if ($docIsLU) {
                print("LU - " . $documentNode->getName() . "\r\n");
                $parent = $documentNode->getParent();
                $moduleToUpdate[$parent->getId()] = $parent;
                $learningUnitDocument = $this->resourceManager
                    ->getResourceFromNode($documentNode);

                $learningUnitDocument->setShowOverview(true);
                $learningUnitDocument->setOverviewMessage(
                    <<<HTML
<table class="table table-striped table-hover table-condensed data-table" style="height: 133px; width: 100%; border-collapse: collapse; margin-left: auto; margin-right: auto;" border="1" cellspacing="5px" cellpadding="20px">
<tbody>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Learning unit','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.slug}}">{{ resource.resourceNode.name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Module','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-2].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-2].slug}}">{{ resource.resourceNode.path[-2].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Course','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-3].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-3].slug}}">{{ resource.resourceNode.path[-3].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Who is it for?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["professional-profile"]}}{{childrenNames}}{{/resource.resourceNode.tags["professional-profile"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('What is included?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["included-resource-type"]}}{{childrenNames}}{{/resource.resourceNode.tags["included-resource-type"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('How long will it take?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["time-frame"]}}{{childrenNames}}{{/resource.resourceNode.tags["time-frame"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Last updated','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.meta.updated}}{{formatDate}}{{/resource.resourceNode.meta.updated}}</td>
</tr>
</tbody>
</table>
HTML
                );

                $learningUnitDocument->setShowDescription(true);
                $learningUnitDocument->setDisclaimer(
                    <<<HTML
<p id="disclaimer-start">{{#resource.resourceNode.tags["disclaimer"] }}</p>
<h3>{trans('Disclaimer','clarodoc')}</h3>
<p class="p1">{trans('This learning unit contains images that may not be accessible to some learners. This content is used to support learning. Whenever possible the information presented in the images is explained in the text.','clarodoc')}</p>
<p>{{/resource.resourceNode.tags["disclaimer"] }}</p>
HTML
                );

                $learningUnitDocument->setWidgetsPagination(true);
                // updated description template
                $learningUnitDocument->setDescriptionTitle(
                    <<<HTML
<h3>{trans('Learning outcomes','clarodoc')}</h3>
HTML
                );
                $documentNode->setDescription($learningOutcomeContent);

                $this->om->persist($documentNode);
                $this->om->persist($learningUnitDocument);
                $user = $documentNode->getCreator();
                $requiredKnowledgeNode = $this->addOrUpdateDocumentSubObject(
                    $user,
                    $documentNode,
                    "Required knowledge",
                    $this->directoryType,
                    false
                );

                // Update translations of description
                $contentTranslations = $this->translations->getAllTranslations(
                    $documentNode
                );
                foreach ($contentTranslations as $key => $contentTranslation) {
                    if ($contentTranslation->getField() == 'description') {
                        $description = $contentTranslation->getContent();
                        $docIsLU = false;
                        $learningOutcomeContent = <<<HTML
                <p><span style="color: #ff0000;"><strong>Author, please fill the outcomes in the resource description</strong></span></p>
                HTML;
                        // update previous description
                        if (!empty($description)) {
                            print("LU Description translation for " . $contentTranslation->getLocale() . "\r\n");
                            // original template
                            $searchedOutcome = explode(
                                "<h3>Learning outcomes</h3>",
                                $description
                            );
                            if (count($searchedOutcome) > 1) {
                                $docIsLU = true;
                                $learningOutcomeContent = explode(
                                    "<p>{{#resource.resourceNode.tags[\"Disclaimer\"] }}</p>",
                                    $searchedOutcome[1]
                                )[0];
                                $learningOutcomeContent = trim($learningOutcomeContent);
                                $learningOutcomeContent = substr(
                                    $learningOutcomeContent,
                                    0,
                                    strlen($learningOutcomeContent)
                                );
                            } else {
                                // template v2
                                // (translations)
                                $searchedOutcome = explode(
                                    "<h3>{trans('Learning outcomes','clarodoc')}</h3>",
                                    $description
                                );
                                if (count($searchedOutcome) > 1) {
                                    $docIsLU = true;
                                    $learningOutcomeContent = explode(
                                        "<p id=\"disclaimer-start\">",
                                        $searchedOutcome[1]
                                    )[0];
                                    $learningOutcomeContent = trim($learningOutcomeContent);
                                }
                            }
                            if ($docIsLU) {
                                $contentTranslation->setContent($learningOutcomeContent);
                                $this->om->persist($contentTranslation);
                            }
                        }
                    }
                }

                $learningUnitDocument->setRequiredResourceNodeTreeRoot($requiredKnowledgeNode);
                $this->om->persist($learningUnitDocument);
            }
        }
        $this->om->flush();

        // Update Modules
        // - update the learning units list widget to display
        // the name and description columns
        $courseToUpdate = [];
        foreach ($moduleToUpdate as $id => $moduleNode) {
            $parent = $moduleNode->getParent();
            if (!empty($parent) && $parent->getResourceType() == $this->documentType) {
                $courseToUpdate[$parent->getId()] = $parent;
            }

            print("Module - " . $moduleNode->getName() . "\r\n");

            $moduleNode->setResourceType($this->documentType);
            $moduleNode->setMimeType("custom/sidpt_document");
            $this->om->persist($moduleNode);

            $moduleDocument = $this->resourceManager
                ->getResourceFromNode($moduleNode);

            $moduleDocument->setShowOverview(false);
            $moduleDocument->setWidgetsPagination(false);

            $this->addOrUpdateResourceListWidget($moduleDocument, $moduleNode, "Learning units");
            $this->om->persist($moduleDocument);
            $this->om->persist($moduleNode);
            $this->om->flush();
        }

        // Update Courses
        // - update the Module list widget to display
        // the name and description columns
        foreach ($courseToUpdate as $id => $courseNode) {
            print("Course - " . $courseNode->getName() . "\r\n");

            $courseDocument = $this->resourceManager
                ->getResourceFromNode($courseNode);
            $courseDocument->setShowOverview(false);
            $courseDocument->setWidgetsPagination(false);

            $this->addOrUpdateResourceListWidget($courseDocument, $courseNode, "Modules");
            $this->om->persist($courseDocument);
            $this->om->persist($courseNode);
            $this->om->flush();
        }

        return 0;
    }

    /**
     * September update 2 : update all document descriptions
     * that match the old format with the new one
     * @param  InputInterface  $input  [description]
     * @param  OutputInterface $output [description]
     * @return [type]                  [description]
     */
    protected function september2021Update1(InputInterface $input, OutputInterface $output) : int
    {
        $documents = $this->resourceNodeRepo->findBy(
            ['mimeType' => 'custom/sidpt_document']
        );

        foreach ($documents as $key => $documentNode) {
            $description = $documentNode->getDescription();
            $docIsLU = false;
            $learningOutcomeContent = <<<HTML
                <p><span style="color: #ff0000;"><strong>Author, please fill this section</strong></span></p>
                HTML;
            // update previous description
            if (!empty($description)) {
                // original template
                $searchedOutcome = explode(
                    "<h3>Learning outcome</h3>",
                    $description
                );
                if (count($searchedOutcome) > 1) {
                    $docIsLU = true;
                    $learningOutcomeContent = explode(
                        "<p>{{#resource.resourceNode.tags[\"Disclaimer\"] }}</p>",
                        $searchedOutcome[1]
                    )[0];
                    $learningOutcomeContent = trim($learningOutcomeContent);
                    $learningOutcomeContent = substr(
                        $learningOutcomeContent,
                        0,
                        strlen($learningOutcomeContent)
                    );
                } else {
                    // template v2
                    // (translations)
                    $searchedOutcome = explode(
                        "<h3>{trans('Learning outcome','clarodoc')}</h3>",
                        $description
                    );
                    if (count($searchedOutcome) > 1) {
                        $docIsLU = true;
                        $learningOutcomeContent = explode(
                            "<p id=\"disclaimer-start\">",
                            $searchedOutcome[1]
                        )[0];
                        $learningOutcomeContent = trim($learningOutcomeContent);
                    }
                }
            }
            if ($docIsLU) {
                $learningUnitDocument = $this->resourceManager
                    ->getResourceFromNode($documentNode);

                $learningUnitDocument->setShowOverview(true);
                $learningUnitDocument->setWidgetsPagination(true);
                // updated description template
                $documentNode->setDescription(
                    <<<HTML
<table class="table table-striped table-hover table-condensed data-table" style="height: 133px; width: 100%; border-collapse: collapse; margin-left: auto; margin-right: auto;" border="1" cellspacing="5px" cellpadding="20px">
<tbody>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Learning unit','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.slug}}">{{ resource.resourceNode.name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Module','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-2].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-2].slug}}">{{ resource.resourceNode.path[-2].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Course','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-3].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-3].slug}}">{{ resource.resourceNode.path[-3].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Who is it for?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["professional-profile"]}}{{childrenNames}}{{/resource.resourceNode.tags["professional-profile"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('What is included?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["included-resource-type"]}}{{childrenNames}}{{/resource.resourceNode.tags["included-resource-type"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('How long will it take?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["time-frame"]}}{{childrenNames}}{{/resource.resourceNode.tags["time-frame"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Last updated','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.meta.updated}}{{formatDate}}{{/resource.resourceNode.meta.updated}}</td>
</tr>
</tbody>
</table>
<h3>{trans('Learning outcome','clarodoc')}</h3>
{$learningOutcomeContent}
<p id="disclaimer-start">{{#resource.resourceNode.tags["disclaimer"] }}</p>
<h3>{trans('Disclaimer','clarodoc')}</h3>
<p class="p1">{trans('This learning unit contains images that may not be accessible to some learners. This content is used to support learning. Whenever possible the information presented in the images is explained in the text.','clarodoc')}</p>
<p>{{/resource.resourceNode.tags["disclaimer"] }}</p>
HTML//end of document
                );

                $this->om->persist($documentNode);
                $this->om->persist($learningUnitDocument);
                $user = $documentNode->getCreator();
                $requiredKnowledgeNode = $this->addOrUpdateDocumentSubObject(
                    $user,
                    $documentNode,
                    "Required knowledge",
                    $this->directoryType,
                    false
                );
                $learningUnitDocument->setRequiredResourceNodeTreeRoot($requiredKnowledgeNode);
                $this->om->persist($learningUnitDocument);
            }
        }
        return 0;
    }

    /**
     * August update 1 : replace all LU binders by document
     * @param  InputInterface  $input  [description]
     * @param  OutputInterface $output [description]
     * @return [type]                  [description]
     */
    protected function august2021Update1(InputInterface $input, OutputInterface $output): int
    {

        // 26/08/2021
        // - for each document placed under a binder
        $documents = $this->resourceNodeRepo->findBy(
            ['mimeType' => 'custom/sidpt_document']
        );
        $moduleToUpdate = [];
        foreach ($documents as $key => $documentNode) {
            $parent = $documentNode->getParent();
            if (!empty($parent)
                && $parent->getResourceType() == $this->binderType
            ) {
                print("LU - " . $documentNode->getName() . "\r\n");
                // if it is an old module or course summary, delete it
                if ($documentNode->getName() == "Summary") {
                    $this->om->remove($documentNode);
                } else {
                    // I assume that it is an old learning unit
                    // keep module binder to be replaced by a document
                    $moduleToUpdate[$parent->getId()] = $parent;
                    $user = $documentNode->getCreator();
                    // Reset/Update learning unit
                    $learningUnitDocument = $this->resourceManager
                        ->getResourceFromNode($documentNode);

                    $learningUnitDocument->setShowOverview(true);
                    $learningUnitDocument->setWidgetsPagination(true);
                    $description = $documentNode->getDescription();

                    $learningOutcomeContent = <<<HTML
                    <span style="color: #ff0000;"><strong>Author, please fill this section</strong></span>
                    HTML;
                    // update previous description
                    if (!empty($description)) {
                        // original template
                        $searchedOutcome = explode(
                            "<h3>Learning outcome</h3>",
                            $description
                        );
                        if (count($searchedOutcome) > 1) {
                            $learningOutcomeContent = explode(
                                "<p>{{#resource.resourceNode.tags[\"Disclaimer\"] }}</p>",
                                $searchedOutcome[1]
                            )[0];
                            $learningOutcomeContent = trim($learningOutcomeContent);
                        } else {
                            // template v2
                            // (translations
                            //  for outcome and disclaimer start)
                            $searchedOutcome = explode(
                                "<h3>{trans('Learning outcome','clarodoc')}</h3>",
                                $description
                            );
                            if (count($searchedOutcome) > 1) {
                                $learningOutcomeContent = explode(
                                    "<p id=\"disclaimer-start\">",
                                    $searchedOutcome[1]
                                )[0];
                                $learningOutcomeContent = trim($learningOutcomeContent);
                            }
                        }
                    }
                    // updated description template
                    $documentNode->setDescription(
                        <<<HTML
<table class="table table-striped table-hover table-condensed data-table" style="height: 133px; width: 100%; border-collapse: collapse; margin-left: auto; margin-right: auto;" border="1" cellspacing="5px" cellpadding="20px">
<tbody>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Learning unit','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.slug}}">{{ resource.resourceNode.name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Module','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-2].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-2].slug}}">{{ resource.resourceNode.path[-2].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Course','clarodoc')}</td>
<td class="text-left string-cell" style="width: 50%; height: 19px;"><a id="{{ resource.resourceNode.path[-3].slug }}" class="list-primary-action default" href="#/desktop/workspaces/open/{{resource.resourceNode.workspace.slug}}/resources/{{resource.resourceNode.path[-3].slug}}">{{ resource.resourceNode.path[-3].name }}</a></td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Who is it for?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["professional-profile"]}}{{childrenNames}}{{/resource.resourceNode.tags["professional-profile"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('What is included?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["included-resource-type"]}}{{childrenNames}}{{/resource.resourceNode.tags["included-resource-type"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('How long will it take?','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.tags["time-frame"]}}{{childrenNames}}{{/resource.resourceNode.tags["time-frame"]}}</td>
</tr>
<tr style="height: 19px;">
<td style="width: 50%; height: 19px;">{trans('Last updated','clarodoc')}</td>
<td style="width: 50%; height: 19px;">{{#resource.resourceNode.meta.updated}}{{formatDate}}{{/resource.resourceNode.meta.updated}}</td>
</tr>
</tbody>
</table>
<h3>{trans('Learning outcome','clarodoc')}</h3>
{$learningOutcomeContent}
<p id="disclaimer-start">{{#resource.resourceNode.tags["disclaimer"] }}</p>
<h3>{trans('Disclaimer','clarodoc')}</h3>
<p class="p1">{trans('This learning unit contains images that may not be accessible to some learners. This content is used to support learning. Whenever possible the information presented in the images is explained in the text.','clarodoc')}</p>
<p>{{/resource.resourceNode.tags["disclaimer"] }}</p>
HTML//end of document
                    );

                    $this->om->persist($documentNode);
                    $this->om->persist($learningUnitDocument);

                    $requiredKnowledgeNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "Required knowledge",
                        $this->directoryType,
                        false
                    );
                    $learningUnitDocument->setRequiredResourceNodeTreeRoot($requiredKnowledgeNode);
                    $this->om->persist($learningUnitDocument);

                    $practiceNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "Practice",
                        $this->exerciseType
                    );
                    $theoryNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "Theory",
                        $this->lessonType
                    );
                    $assessmentNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "Assessment",
                        $this->exerciseType
                    );
                    $activityNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "Activity",
                        $this->textType
                    );
                    $referencesNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $documentNode,
                        "References",
                        $this->documentType
                    );

                    $referencesDocument = $this->resourceManager->getResourceFromNode($referencesNode);

                    $externalReferencesNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $referencesNode,
                        "External references",
                        $this->textType
                    );
                    $internalReferencesNode = $this->addOrUpdateDocumentSubObject(
                        $user,
                        $referencesNode,
                        "IPIP references",
                        $this->directoryType
                    );

                }
                $this->om->flush();
            }
        }

        $courseToUpdate = [];
        foreach ($moduleToUpdate as $id => $moduleNode) {
            $parent = $moduleNode->getParent();
            if (!empty($parent)
                && $parent->getResourceType() == $this->binderType
            ) {
                $courseToUpdate[$parent->getId()] = $parent;
            }

            print("Module - " . $moduleNode->getName() . "\r\n");

            $moduleNode->setResourceType($this->documentType);
            $moduleNode->setMimeType("custom/sidpt_document");
            $this->om->persist($moduleNode);

            $moduleDocument = new Document();
            $moduleDocument->setResourceNode($moduleNode);

            $moduleDocument->setName($moduleNode->getName());
            $moduleDocument->setShowOverview(false);
            $moduleDocument->setWidgetsPagination(false);

            $this->addOrUpdateResourceListWidget($moduleDocument, $moduleNode, "Learning units");
            $this->om->persist($moduleDocument);
            $this->om->persist($moduleNode);
            $this->om->flush();
        }

        foreach ($courseToUpdate as $id => $courseNode) {
            print("Course - " . $courseNode->getName() . "\r\n");
            $courseNode->setResourceType($this->documentType);
            $courseNode->setMimeType("custom/sidpt_document");
            $this->om->persist($courseNode);

            $courseDocument = new Document();
            $courseDocument->setResourceNode($courseNode);

            $courseDocument->setName($courseNode->getName());
            $courseDocument->setShowOverview(false);
            $courseDocument->setWidgetsPagination(false);

            $this->addOrUpdateResourceListWidget($courseDocument, $courseNode, "Modules");
            $this->om->persist($courseDocument);
            $this->om->persist($courseNode);
            $this->om->flush();
        }

        return 0;
    }

    public function addOrUpdateDocumentSubObject(
        $user,
        $documentNode,
        $subnodeName,
        $resourceType,
        $withWidget = true
    ) {
        $document = $this->resourceManager->getResourceFromNode($documentNode);
        $subNode = $this->resourceNodeRepo->findOneBy(
            [
                'name' => $subnodeName,
                'parent' => $documentNode->getId(),
                'resourceType' => $resourceType->getId(),
            ]
        );
        if (empty($subNode)) {
            $subNode = new ResourceNode();
            $subNode->setName($subnodeName);
            $subNode->setWorkspace($documentNode->getWorkspace());
            $subNode->setResourceType($resourceType);
            $subNode->setParent($documentNode);
            $subNode->setCreator($user);
            $subNode->setMimeType("custom/" . $resourceType->getName());
            $this->om->persist($subNode);

            $resourceclass = $resourceType->getClass();
            $subResource = new $resourceclass();
            $subResource->setResourceNode($subNode);
            $subResource->setName($subnodeName);

            if ($resourceType->getName() == "ujm_exercise") {
                if ($subnodeName == "Practice") {
                    $subResource->setType(ExerciseType::CONCEPTUALIZATION);
                    $subResource->setScoreRule(json_encode(["type" => "none"]));
                } else if ($subnodeName == "Assessment") {
                    $subResource->setType(ExerciseType::SUMMATIVE);
                    $subResource->setScoreRule(json_encode(["type" => "sum"]));
                }
            }

            $this->om->persist($subResource);

            $this->om->persist($document);

            if ($withWidget) {
                $this->addResourceWidget($document, $subNode, $subnodeName);
            }
        } else {
            // Update the document or node
            // Update the underlying resource
            $subResource = $this->resourceManager->getResourceFromNode($subNode);
            if ($subResource->getMimeType() == "custom/ujm_exercise") {
                if ($subResource->getType() == ExerciseType::SUMMATIVE) {
                    if (empty($subResource->getScoreRule())) {
                        $subResource->setScoreRule(
                            json_encode(["type" => "none"])
                        );
                    }
                } else if ($subResource->getType() == ExerciseType::SUMMATIVE) {
                    if (empty($subResource->getScoreRule())) {
                        $subResource->setScoreRule(
                            json_encode(["type" => "sum"])
                        );
                    }
                }
            }
            // update the widget
            $subNodeWidgets = $this->resourceWidgetsRepo->findBy(
                [
                    'resourceNode' => $subNode->getId(),
                ]
            );
            if (!empty($subNodeWidgets)) {
                // widget was found
                foreach ($subNodeWidgets as $widget) {
                    $widget->setShowResourceHeader(false);
                    $instance = $widget->getWidgetInstance();
                    $container = $instance->getContainer();
                    if (!$withWidget) {
                        // widget is no more requested
                        // remove the widget container
                        $this->om->remove($container);
                        $this->om->remove($instance);
                        $this->om->remove($widget);
                    } else {
                        $containerConfig = $container->getWidgetContainerConfigs()->first();
                        $containerConfig->setName($subnodeName);

                        $this->om->persist($widget);
                    }
                }
            } elseif ($withWidget) {
                // subnode is alledgedly used in a widget but was not found
                // So we add it
                $this->addResourceWidget($document, $subNode, $subnodeName);
            }
        }
        $this->om->flush();
        return $subNode;
    }

    /**
     * [addResourceWidget description]
     * @param [type] $document     [description]
     * @param [type] $resourceNode [description]
     */
    public function addOrUpdateResourceListWidget($document, $parentNode, $name = null)
    {
        if ($document->getWidgetContainers()->isEmpty()) {
            $newWidget = new ListWidget();
            $newWidget->setFilters(
                [0 => [
                    "property" => "parent",
                    "value" => [
                        "id" => $parentNode->getUuid(),
                        "name" => $parentNode->getName(),
                    ],
                    "locked" => true,
                ],
                ]
            );

            $widget->setDisplay("table");
            $widget->setActions(false);
            $widget->setCount(true);
            $widget->setDisplayedColumns(["name", "meta.description"]);
            if ($name == "Learning units") {
                // update widget to display two columns
                // - the name column should be labeled "Select a learning unit" with a
                // - the meta.description should be title "Learning outcomes"
                $widget->setColumnsCustomization(
                    [
                        "name" => [
                            "label" => "Select a learning unit",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                        "meta.description" => [
                            "label" => "Learning outcomes",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                    ]
                );
            } elseif ($name == "Modules") {
                // update widget to display two columns
                // - the name column should be labeled "Select a module" with a
                // - the meta.description title should be removed
                $widget->setColumnsCustomization(
                    [
                        "name" => [
                            "label" => "Select a module",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                        "meta.description" => [
                            "hideLabel" => true,
                        ],
                    ]
                );
            } else {
                // possibly course list, but might be done manually
            }

            $newWidgetInstance = new WidgetInstance();
            $newWidgetInstance->setWidget($this->listWidgetType);
            $newWidgetInstance->setDataSource($this->resourcesListDataSource);
            $newWidget->setWidgetInstance($newWidgetInstance);
            $newWidgetInstanceConfig = new WidgetInstanceConfig();
            $newWidgetInstanceConfig->setType("list");
            $newWidgetInstanceConfig->setWidgetInstance($newWidgetInstance);
            $newWidgetContainer = new WidgetContainer();
            $newWidgetContainer->addInstance($newWidgetInstance);
            $newWidgetInstance->setContainer($newWidgetContainer);
            $newWidgetContainerConfig = new WidgetContainerConfig();
            $newWidgetContainerConfig->setName($name);
            $newWidgetContainerConfig->setBackgroundType("color");
            $newWidgetContainerConfig->setBackground("#ffffff");
            $newWidgetContainerConfig->setPosition(0);
            $newWidgetContainerConfig->setLayout(array(1));
            $newWidgetContainerConfig->setWidgetContainer($newWidgetContainer);
            $this->om->persist($newWidget);
            $this->om->persist($newWidgetInstance);
            $this->om->persist($newWidgetContainer);

            $document->addWidgetContainer($newWidgetContainer);
        } else {
            $container = $document->getWidgetContainers()->first();
            $containerConfig = $container->getWidgetContainerConfigs()->first();
            $instance = $container->getInstances()->first();

            $widget = $this->listWidgetsRepo->findOneBy(
                [
                    "widgetInstance" => $instance->getId(),
                ]
            );

            $widget->setFilters(
                [0 => [
                    "property" => "parent",
                    "value" => [
                        "id" => $parentNode->getUuid(),
                        "name" => $parentNode->getName(),
                    ],
                    "locked" => true,
                ],
                ]
            );
            $widget->setDisplay("table");
            $widget->setActions(false);
            $widget->setCount(true);
            $widget->setDisplayedColumns(["name", "meta.description"]);

            $containerConfig->setName($name);

            if ($name == "Learning units") {
                // update widget to display two columns
                // - the name column should be labeled "Select a learning unit" with a
                // - the meta.description should be title "Learning outcomes"
                $widget->setColumnsCustomization(
                    [
                        "name" => [
                            "label" => "Select a learning unit",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                        "meta.description" => [
                            "label" => "Learning outcomes",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                    ]
                );
            } elseif ($name == "Modules") {
                // update widget to display two columns
                // - the name column should be labeled "Select a module" with a
                // - the meta.description title should be removed
                $widget->setColumnsCustomization(
                    [
                        "name" => [
                            "label" => "Select a module",
                            "translateLabel" => true,
                            "translationDomain" => 'clarodoc',
                        ],
                        "meta.description" => [
                            "hideLabel" => true,
                        ],
                    ]
                );
            } else {
                // possibly course list, but might be done manually
            }

            $this->om->persist($widget);
            $this->om->persist($containerConfig);
        }
        $this->om->persist($document);
        $this->om->flush();
    }

    /**
     * [addResourceWidget description]
     * @param [type] $document     [description]
     * @param [type] $resourceNode [description]
     */
    public function addResourceWidget($document, $resourceNode, $name = null)
    {
        $newWidget = new ResourceWidget();
        $newWidget->setResourceNode($resourceNode);
        $newWidget->setShowResourceHeader(false);
        $this->om->persist($newWidget);

        $newWidgetInstance = new WidgetInstance();
        $newWidgetInstance->setWidget($this->resourceWidgetType);
        $newWidgetInstance->setDataSource($this->resourceDataSource);
        $this->om->persist($newWidgetInstance);
        $newWidget->setWidgetInstance($newWidgetInstance);

        $newWidgetInstanceConfig = new WidgetInstanceConfig();
        $newWidgetInstanceConfig->setType("resource");
        $newWidgetInstanceConfig->setWidgetInstance($newWidgetInstance);
        $this->om->persist($newWidgetInstanceConfig);

        $newWidgetContainer = new WidgetContainer();
        $newWidgetContainer->addInstance($newWidgetInstance);
        $newWidgetInstance->setContainer($newWidgetContainer);
        $this->om->persist($newWidgetContainer);

        $newWidgetContainerConfig = new WidgetContainerConfig();
        $newWidgetContainerConfig->setName($name);
        $newWidgetContainerConfig->setBackgroundType("color");
        $newWidgetContainerConfig->setBackground("#ffffff");
        $newWidgetContainerConfig->setPosition(0);
        $newWidgetContainerConfig->setLayout(array(1));
        $newWidgetContainerConfig->setWidgetContainer($newWidgetContainer);
        $this->om->persist($newWidgetContainerConfig);

        $document->addWidgetContainer($newWidgetContainer);
        $this->om->persist($document);
    }
}
