<?php
namespace Skill\Controller\Item;

use App\Controller\AdminEditIface;
use Dom\Template;
use Tk\Form\Event;
use Tk\Form\Field;
use Tk\Request;


/**
 * @author Michael Mifsud <info@tropotek.com>
 * @link http://www.tropotek.com/
 * @license Copyright 2015 Michael Mifsud
 */
class Edit extends AdminEditIface
{

    /**
     * @var \Skill\Db\Item
     */
    protected $item = null;



    /**
     * Iface constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setPageTitle('Skill Item Edit');
    }

    /**
     *
     * @param Request $request
     */
    public function doDefault(Request $request)
    {
        $this->item = new \Skill\Db\Item();
        $this->item->collectionId = (int)$request->get('collectionId');
        if ($request->get('itemId')) {
            $this->item = \Skill\Db\ItemMap::create()->find($request->get('itemId'));
        }

        $this->buildForm();

        $this->form->load(\Skill\Db\ItemMap::create()->unmapForm($this->item));
        $this->form->execute($request);
    }


    protected function buildForm() 
    {
        $this->form = \App\Config::getInstance()->createForm('itemEdit');
        $this->form->setRenderer(\App\Config::getInstance()->createFormRenderer($this->form));

        //$this->form->addField(new Field\Input('uid'))->setNotes('(optional) Use this to match up questions from other collections, for generating reports');

        $list = \Skill\Db\CategoryMap::create()->findFiltered(array('collectionId' => $this->item->getCollection()->getId()));
        $this->form->addField(new Field\Select('categoryId', \Tk\Form\Field\Option\ArrayObjectIterator::create($list)))->prependOption('-- Select --', '')->setNotes('');

        $list = \Skill\Db\DomainMap::create()->findFiltered(array('collectionId' => $this->item->getCollection()->getId()));
        $this->form->addField(new Field\Select('domainId', \Tk\Form\Field\Option\ArrayObjectIterator::create($list)))->prependOption('-- None --', '')->setNotes('');

        $this->form->addField(new Field\Input('question'))->setRequired()->setNotes('The question text to display');
        $this->form->addField(new Field\Input('description'))->setNotes('Description or help text');
        $this->form->addField(new Field\Checkbox('publish'))->setNotes('');

        $this->form->addField(new Event\Button('update', array($this, 'doSubmit')));
        $this->form->addField(new Event\Button('save', array($this, 'doSubmit')));
        $this->form->addField(new Event\Link('cancel', \Uni\Ui\Crumbs::getInstance()->getBackUrl()));

    }

    /**
     * @param \Tk\Form $form
     */
    public function doSubmit($form)
    {
        // Load the object with data from the form using a helper object
        \Skill\Db\ItemMap::create()->mapForm($form->getValues(), $this->item);


        $form->addFieldErrors($this->item->validate());

        if ($form->hasErrors()) {
            return;
        }
        $this->item->save();

        \Tk\Alert::addSuccess('Record saved!');
        if ($form->getTriggeredEvent()->getName() == 'update') {
            \Uni\Ui\Crumbs::getInstance()->getBackUrl()->redirect();
        }
        \Tk\Uri::create()->set('itemId', $this->item->getId())->redirect();
    }

    /**
     * @return \Dom\Template
     */
    public function show()
    {
        $template = parent::show();

        // Render the form
        $template->insertTemplate('form', $this->form->getRenderer()->show());

        return $template;
    }

    /**
     * DomTemplate magic method
     *
     * @return Template
     */
    public function __makeTemplate()
    {
        $xhtml = <<<HTML
<div>
    
  <div class="panel panel-default">
    <div class="panel-heading">
      <h4 class="panel-title"><i class="fa fa-question"></i> <span var="panel-title">Skill Item Edit</span></h4>
    </div>
    <div class="panel-body">
      <div var="form"></div>
    </div>
  </div>
  
</div>
HTML;

        return \Dom\Loader::load($xhtml);
    }

}