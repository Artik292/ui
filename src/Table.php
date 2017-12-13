<?php

// vim:ts=4:sw=4:et:fdm=marker:fdl=0

namespace atk4\ui;

class Table extends Lister
{
    use \atk4\core\HookTrait;

    // Overrides
    public $defaultTemplate = 'table.html';
    public $ui = 'table';
    public $content = false;

    /**
     * If table is part of Grid or CRUD, we want to reload that instead of grid.
     */
    public $reload = null;

    /**
     * Column objects can service multiple columns. You can use it for your advancage by re-using the object
     * when you pass it to addColumn(). If you omit the argument, then a column of a type 'Generic' will be
     * used.
     *
     * @var Column\Generic
     */
    public $default_column = null;

    /**
     * Contains list of declared columns. Value will always be a column object.
     *
     * @var array
     */
    public $columns = [];

    /**
     * Allows you to inject HTML into table using getHTMLTags hook and column call-backs.
     * Switch this feature off to increase performance at expense of some row-specific HTML.
     *
     * @var bool
     */
    public $use_html_tags = true;

    /**
     * Setting this to false will hide header row.
     *
     * @var bool
     */
    public $header = true;

    /**
     * Determines a strategy on how totals will be calculated. Do not touch those fields
     * directly, instead use addTotals() or setTotals().
     *
     * @var array
     */
    public $totals_plan = [];

    /**
     * Contains list of totals accumulated during the render process.
     *
     * Don't use this property directly. Use addTotals() and setTotals() instead.
     *
     * @var array
     */
    public $totals = [];

    /**
     * Contain the template for the "Head" type row.
     *
     * @var Template
     */
    protected $t_head;

    /**
     * Contain the template for the "Body" type row.
     *
     * @var Template
     */
    protected $t_row;

    /**
     * Contain the template for the "Foot" type row.
     *
     * @var Template
     */
    protected $t_totals;

    /**
     * Contains the output to show if table contains no rows.
     *
     * @var Template
     */
    protected $t_empty;

    /** @var bool */
    public $sortable = false;

    public $sort_by = null;

    public $sort_order = null;

    public function __construct($class = null)
    {
        if ($class) {
            $this->addClass($class);
        }
    }

    /**
     * Defines a new column for this field. You need two objects for field to
     * work.
     *
     * First is being Model field. If your Table is already associated with
     * the model, it will automatically pick one by looking up element
     * corresponding to the $name or add it as per your definition inside $field.
     *
     * The other object is a Column Decorator. This object know how to produce HTML for
     * cells and will handle other things, like alignment. If you do not specify
     * column, then it will be selected dynamically based on field type.
     *
     * @param string                   $name            Data model field name
     * @param array|string|object|null $columnDecorator
     * @param array|string|object|null $field
     *
     * @return Column\Generic
     */
    public function addColumn($name, $columnDecorator = null, $field = null)
    {
        if (!$this->_initialized) {
            throw new Exception\NoRenderTree($this, 'addColumn()');
        }

        if (!$this->model) {
            $this->model = new \atk4\ui\misc\ProxyModel();
        }

        // This code should be vaugely consistent with FormLayout\Generic::addField()

        if (is_string($field)) {
            $field = ['type' => $field];
        }

        if ($name) {
            $existingField = $this->model->hasElement($name);
        } else {
            $existingField = null;
        }

        if (!$existingField) {
            // Add missing field
            if ($field) {
                $field = $this->model->addField($name, $field);
                $field->never_persist = true;
            } else {
                $field = $this->model->addField($name);
                $field->never_persist = true;
            }
        } elseif (is_array($field)) {
            // Add properties to existing field
            $existingField->setDefaults($field);
            $field = $existingField;
        } elseif (is_object($field)) {
            throw new Exception(['Duplicate field', 'name' => $name]);
        } else {
            $field = $existingField;
        }

        if (is_array($columnDecorator) || is_string($columnDecorator)) {
            $columnDecorator = $this->decoratorFactory($field, $columnDecorator);
        } elseif (!$columnDecorator) {
            $columnDecorator = $this->decoratorFactory($field);
        } elseif (is_object($columnDecorator)) {
            if (!$columnDecorator instanceof \atk4\ui\TableColumn\Generic) {
                throw new Exception(['Column decorator must descend from \atk4\ui\TableColumn\Generic', 'columnDecorator' => $columnDecorator]);
            }
            $columnDecorator->table = $this;
            $this->_add($columnDecorator);
        } else {
            throw new Exception(['Value of $columnDecorator argument is incorrect', 'columnDecorator' => $columnDecorator]);
        }

        if (is_null($name)) {
            $this->columns[] = $columnDecorator;
        } elseif (!is_string($name)) {
            echo 'about to throw exception.....';

            throw new Exception(['Name must be a string', 'name' => $name]);
        } elseif (isset($this->columns[$name])) {
            throw new Exception(['Table already has column with $name. Try using addDecorator()', 'name' => $name]);
        } else {
            $this->columns[$name] = $columnDecorator;
        }

        return $columnDecorator;
    }

    public function addDecorator($name, $decorator)
    {
        if (!$this->columns[$name]) {
            throw new Exceptino(['No such column, cannot decorate', 'name' => $name]);
        }
        $decorator = $this->_add($this->factory($decorator, ['table' => $this], 'TableColumn'));

        if (!is_array($this->columns[$name])) {
            $this->columns[$name] = [$this->columns[$name]];
        }
        $this->columns[$name][] = $decorator;
    }

    /**
     * Will come up with a column object based on the field object supplied.
     * By default will use default column.
     *
     * @param \atk4\data\Field $f    Data model field
     * @param array            $seed Defaults to pass to factory() when decorator is initialized
     *
     * @return TableColumn\Generic
     */
    public function decoratorFactory(\atk4\data\Field $f, $seed = [])
    {
        $seed = $this->mergeSeeds(
            $seed,
            isset($f->ui['table']) ? $f->ui['table'] : null,
            isset($this->typeToDecorator[$f->type]) ? $this->typeToDecorator[$f->type] : null,
            ['Generic']
        );

        return $this->_add($this->factory($seed, ['table' => $this], 'TableColumn'));
    }

    protected $typeToDecorator = [
        'password' => 'Password',
        'text'     => 'Text',
        'boolean'  => ['Status', ['positive' => [true], 'negative' => ['false']]],
    ];

    /**
     * Adds totals calculation plan.
     * You can call this method multiple times to add more than one totals row.
     *
     * @param array $plan
     *
     * @return $this
     */
    public function addTotals($plan = [])
    {
        // normalize plan
        foreach ($plan as $field => &$def) {
            // title
            if (is_string($def)) {
                $def = ['title' => $def];
            }

            // callable
            if (is_callable($def)) {
                $def = ['row' => $def];
            }

            // built-in method
            if (is_array($def) && isset($def[0]) && is_string($def[0])) {
                $def = ['row' => $def[0]];
            }
        }

        $this->totals_plan[] = $plan;

        return $this;
    }

    /**
     * Sets totals calculation plan.
     * This will overwrite all previously set plans.
     *
     * @param array $plan
     *
     * @return $this
     */
    public function setTotals($plan = [])
    {
        $this->totals_plan = [];

        return $this->addTotals($plan);
    }

    /**
     * Init method will create one column object that will be used to render
     * all columns in the table unless you have specified a different
     * column object.
     */
    public function init()
    {
        parent::init();

        if (!$this->t_head) {
            $this->t_head = $this->template->cloneRegion('Head');
            $this->t_row_master = $this->template->cloneRegion('Row');
            $this->t_totals = $this->template->cloneRegion('Totals');
            $this->t_empty = $this->template->cloneRegion('Empty');

            $this->template->del('Head');
            $this->template->del('Body');
            $this->template->del('Foot');
        }
    }

    /**
     * Sets data Model of Table.
     *
     * If $columns is not defined, then automatically will add columns for all
     * visible model fields. If $columns is set to false, then will not add
     * columns at all.
     *
     * @param \atk4\data\Model $m       Data model
     * @param array|bool       $columns
     *
     * @return \atk4\data\Model
     */
    public function setModel(\atk4\data\Model $m, $columns = null)
    {
        parent::setModel($m);

        if ($columns === null) {
            $columns = [];
            foreach ($m->elements as $name => $element) {
                if (!$element instanceof \atk4\data\Field) {
                    continue;
                }

                if ($element->isVisible()) {
                    $columns[] = $name;
                }
            }
        } elseif ($columns === false) {
            return;
        }

        foreach ($columns as $column) {
            $this->addColumn($column);
        }

        return $this->model;
    }

    /**
     * {@inheritdoc}
     */
    public function renderView()
    {
        if (!$this->columns) {
            throw new Exception(['Table does not have any columns defined', 'columns' => $this->columns]);
        }

        if ($this->sortable) {
            $this->addClass('sortable');
        }

        // Generate Header Row
        if ($this->header) {
            $this->t_head->setHTML('cells', $this->getHeaderRowHTML());
            $this->template->setHTML('Head', $this->t_head->render());
        }

        // Generate template for data row
        $this->t_row_master->setHTML('cells', $this->getDataRowHTML());
        $this->t_row_master['_id'] = '{$_id}';
        $this->t_row = new Template($this->t_row_master->render());
        $this->t_row->app = $this->app;

        // Iterate data rows
        $rows = 0;
        foreach ($this->model as $this->current_id => $tmp) {
            $this->current_row = $this->model->get();
            if ($this->hook('beforeRow') === false) {
                continue;
            }

            if ($this->totals_plan) {
                $this->updateTotals();
            }

            $this->renderRow();

            $rows++;
        }

        // Add totals rows or empty message
        if (!$rows) {
            $this->template->appendHTML('Body', $this->t_empty->render());
        } elseif ($this->totals_plan) {
            foreach (array_keys($this->totals_plan) as $plan_id) {
                $this->t_totals->setHTML('cells', $this->getTotalsRowHTML($plan_id));
                $this->template->appendHTML('Foot', $this->t_totals->render());
            }
        }

        return View::renderView();
    }

    /**
     * Render individual row. Override this method if you want to do more
     * decoration.
     */
    public function renderRow()
    {
        $this->t_row->set($this->model);

        if ($this->use_html_tags) {
            // Prepare row-specific HTML tags.
            $html_tags = [];

            foreach ($this->hook('getHTMLTags', [$this->model]) as $ret) {
                if (is_array($ret)) {
                    $html_tags = array_merge($html_tags, $ret);
                }
            }

            foreach ($this->columns as $name => $columns) {
                if (!is_array($columns)) {
                    $columns = [$columns];
                }
                $field = $this->model->hasElement($name);
                foreach ($columns as $column) {
                    if (method_exists($column, 'getHTMLTags')) {
                        $html_tags = array_merge($column->getHTMLTags($this->model, $field), $html_tags);
                    }
                }
            }

            // Render row and add to body
            $this->t_row->setHTML($html_tags);
            $this->t_row->set('_id', $this->model->id);
            $this->template->appendHTML('Body', $this->t_row->render());
            $this->t_row->del(array_keys($html_tags));
        } else {
            $this->template->appendHTML('Body', $this->t_row->render());
        }
    }

    /**
     * Same as on('click', 'tr', $action), but will also make sure you can't
     * click outside of the body. Additionally when you move cursor over the
     * rows, pointer will be used and rows will be highlighted as you hover.
     *
     * @param jsChain|callable $action Code to execute
     *
     * @return jQuery
     */
    public function onRowClick($action)
    {
        $this->addClass('selectable');
        $this->js(true)->find('tbody')->css('cursor', 'pointer');

        return $this->on('click', 'tbody>tr', $action);
    }

    /**
     * Use this to quickly access the <tr> and wrap in jQuery.
     *
     * $this->jsRow()->data('id');
     *
     * @return jQuery
     */
    public function jsRow()
    {
        return (new jQuery(new jsExpression('this')))->closest('tr');
    }

    /**
     * Executed for each row if "totals" are enabled to add up values.
     * It will calculate requested totals for all total plans.
     */
    public function updateTotals()
    {
        foreach ($this->totals_plan as $plan_id => $plan) {
            $t = &$this->totals[$plan_id]; // shortcut

            foreach ($plan as $key => $def) {

                // simply initialize array key, but don't set any value
                // we can't set initial value to 0, because min/max or some custom totals
                // methods can use this 0 as value for comparison and that's wrong
                if (!isset($t[$key]) && !isset($def['title'])) {
                    if (isset($def['default'])) {
                        $f = $def['default']; // shortcut

                        $t[$key] = is_callable($f)
                            ? call_user_func_array($f, [$this->model[$key], $this->model])
                            : $f;
                    } else {
                        $t[$key] = null;
                    }
                }

                // calc row totals
                if (isset($def['row'])) {
                    $f = $def['row']; // shortcut

                    // built-in functions
                    if (is_string($f)) {
                        switch ($f) {
                            case 'sum':
                                // set initial value
                                $t[$key] = ($t[$key] === null ? 0 : $t[$key]);
                                // sum
                                $t[$key] = $t[$key] + $this->model[$key];
                                break;
                            case 'count':
                                // set initial value
                                $t[$key] = ($t[$key] === null ? 0 : $t[$key]);
                                // increment
                                $t[$key]++;
var_dump($t);
                                break;
                            case 'min':
                                // set initial value
                                $t[$key] = ($t[$key] === null ? $this->model[$key] : $t[$key]);
                                // compare
                                if ($this->model[$key] < $t[$key]) {
                                    $t[$key] = $this->model[$key];
                                }
                                break;
                            case 'max':
                                // set initial value
                                $t[$key] = ($t[$key] === null ? $this->model[$key] : $t[$key]);
                                // compare
                                if ($this->model[$key] > $t[$key]) {
                                    $t[$key] = $this->model[$key];
                                }
                                break;
                            default:
                                throw new Exception(['Aggregation method does not exist', 'column' => $key, 'method' => $f]);
                        }

                        continue;
                    }

                    // Callable support
                    // Arguments:
                    // - current total value
                    // - current field value from model
                    // - \atk4\data\Model table model with current record loaded
                    // Should return new total value (for example, current value + current field value)
                    // NOTE: Keep in mind, that current total value initially can be null !
                    if (is_callable($f)) {
                        $t[$key] = call_user_func_array($f, [$t[$key], $this->model[$key], $this->model]);

                        continue;
                    }
                }
            }
        }
    }

    /**
     * Responds with the HTML to be inserted in the header row that would
     * contain captions of all columns.
     *
     * @return string
     */
    public function getHeaderRowHTML()
    {
        $output = [];
        foreach ($this->columns as $name => $column) {

            // If multiple formatters are defined, use the first for the header cell
            if (is_array($column)) {
                $column = $column[0];
            }

            if (!is_int($name)) {
                $field = $this->model->getElement($name);

                $output[] = $column->getHeaderCellHTML($field);
            } else {
                $output[] = $column->getHeaderCellHTML();
            }
        }

        return implode('', $output);
    }

    /**
     * Responds with HTML to be inserted in the footer row that would
     * contain totals for all columns. This generates only one totals row
     * for particular totals plan with $plan_id.
     *
     * @param int $plan_id
     *
     * @return string
     */
    public function getTotalsRowHTML($plan_id)
    {
        // shortcuts
        $plan = &$this->totals_plan[$plan_id];
        $totals = &$this->totals[$plan_id];

        $output = [];
        foreach ($this->columns as $name => $column) {
            // if no totals plan, then show dash, but keep column formatting
            if (!isset($plan[$name])) {
                $output[] = $column->getTag('foot', '');
                continue;
            }

            // if totals plan is set as array, then show formatted value
            if (is_array($plan[$name]) || is_callable($plan[$name])) {
                // todo - format
                $field = $this->model->getElement($name);
                $output[] = $column->getTotalsCellHTML($field, $totals[$name]);
                continue;
            }

            // otherwise just show it, for example, "Totals:" cell
            $output[] = $column->getTag('foot', $plan[$name]);
        }

        return implode('', $output);
    }

    /**
     * Collects cell templates from all the columns and combine them into row template.
     *
     * @return string
     */
    public function getDataRowHTML()
    {
        $output = [];
        foreach ($this->columns as $name => $column) {

            // If multiple formatters are defined, use the first for the header cell

            if (!is_int($name)) {
                $field = $this->model->getElement($name);
            } else {
                $field = null;
            }

            if (!is_array($column)) {
                $column = [$column];
            }

            // we need to smartly wrap things up
            $cell = null;
            $cnt = count($column);
            $td_attr = [];
            foreach ($column as $c) {
                if (--$cnt) {
                    $html = $c->getDataCellTemplate($field);
                    $td_attr = $c->getTagAttributes('body', $td_attr);
                } else {
                    // Last formatter, ask it to give us whole rendering
                    $html = $c->getDataCellHTML($field, $td_attr);
                }

                if ($cell) {
                    if ($name) {
                        // if name is set, we can wrap things
                        $cell = str_replace('{$'.$name.'}', $cell, $html);
                    } else {
                        $cell = $cell.' '.$html;
                    }
                } else {
                    $cell = $html;
                }
            }

            $output[] = $cell;
        }

        return implode('', $output);
    }
}
