<?php
namespace App\Models;

use App\Models;

use App\Models\AbstractModel;
use Illuminate\Support\Facades\Validator;

use App\Models\MyInterface;

class Product extends AbstractModel implements MyInterface\MultiLanguageInterface
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'products';

    protected $primaryKey = 'id';

    protected $rules = [
        'global_title' => 'required|max:255',
        'status' => 'integer|required',
        'created_by' => 'integer'
    ];

    protected $editableFields = [
        'global_title',
        'status',
        'page_template',
        'order',
        'created_by'
    ];

    public function productContent()
    {
        return $this->hasMany('App\Models\ProductContent', 'product_id');
    }

    public function adminUser()
    {
        return $this->belongsTo('App\Models\AdminUser', 'created_by');
    }

    public function category()
    {
        return $this->belongsToMany('App\Models\ProductCategory', 'product_categories_products', 'product_id', 'category_id');
    }

    public function updateItem($id, $data, $justUpdateSomeFields = false)
    {
        $data['id'] = $id;
        $result = $this->fastEdit($data, true, $justUpdateSomeFields);

        if (!$result['error']) {
            /*Save categories*/
            if (isset($data['category_ids'])) {
                $result['object']->category()->sync($data['category_ids']);
            }
        }
        return $result;
    }

    public function updateItemContent($id, $languageId, $data)
    {
        $result = [
            'error' => true,
            'response_code' => 500,
            'message' => 'Some error occurred!'
        ];

        $post = static::find($id);
        if (!$post) {
            $result['message'] = 'The post you have tried to edit not found.';
            $result['response_code'] = 404;
            return $result;
        }

        if (isset($data['slug'])) $data['slug'] = str_slug($data['slug']);

        /*Save categories*/
        if (isset($data['category_ids'])) {
            $post->category()->sync($data['category_ids']);
        }

        /*Update page template*/
        if (isset($data['page_template'])) {
            $post->page_template = $data['page_template'];
            $post->save();
        }

        /*Update post content*/
        $postContent = static::getContentById($id, $languageId);
        if (!$postContent) {
            $postContent = new ProductContent();
            $postContent->language_id = $languageId;
            $postContent->product_id = $id;
            $postContent->save();
        }

        $data['id'] = $postContent->id;

        return $postContent->fastEdit($data, false, true);
    }

    public static function deleteItem($id)
    {
        $result = [
            'error' => true,
            'response_code' => 500,
            'message' => 'Some error occurred!'
        ];
        $object = static::find($id);

        if (!$object) {
            $result['message'] = 'The product you have tried to edit not found';
            return $result;
        }

        $temp = ProductContent::where('product_id', '=', $id);
        $related = $temp->get();
        if (!count($related)) {
            $related = null;
        }

        /*Remove all related content*/
        if ($related != null) {
            $customFields = ProductMeta::join('product_contents', 'product_contents.id', '=', 'product_metas.content_id')
                ->join('products', 'products.id', '=', 'product_contents.product_id')
                ->where('products.id', '=', $id)
                ->delete();

            if ($temp->delete()) {
                $result['error'] = false;
                $result['response_code'] = 200;
                $result['message'] = ['Delete related content completed!'];
            }
        }

        $object->category()->sync([]);

        if ($object->delete()) {
            $result['error'] = false;
            $result['response_code'] = 200;
            $result['message'] = ['Delete product completed!'];
        }

        return $result;
    }

    public function createItem($language, $data)
    {
        $dataPost = ['status' => 1];
        if (isset($data['title'])) $dataPost['global_title'] = $data['title'];
        if (isset($data['created_by'])) $dataPost['created_by'] = $data['created_by'];
        if (isset($data['category_ids'])) $dataPost['category_ids'] = $data['category_ids'];
        if (!isset($data['status'])) $data['status'] = 1;
        if (!isset($data['language_id'])) $data['language_id'] = $language;

        $resultCreateItem = $this->updateItem(0, $dataPost);

        /*No error*/
        if (!$resultCreateItem['error']) {
            $product_id = $resultCreateItem['object']->id;
            $resultUpdateItemContent = $this->updateItemContent($product_id, $language, $data);
            if($resultUpdateItemContent['error']) {
                $this->deleteItem($resultCreateItem['object']->id);
            }
            return $resultUpdateItemContent;
        }
        return $resultCreateItem;
    }

    public static function getById($id, $languageId = 0, $options = [])
    {
        $options = (array)$options;
        $defaultArgs = [
            'status' => 1,
            'global_status' => 1
        ];
        $args = array_merge($defaultArgs, $options);

        return static::join('product_contents', 'products.id', '=', 'product_contents.product_id')
            ->join('languages', 'languages.id', '=', 'product_contents.language_id')
            ->where('products.id', '=', $id)
            ->where(function ($q) use ($args) {
                if ($args['global_status'] != null) $q->where('products.status', '=', $args['global_status']);
                if ($args['status'] != null) $q->where('product_contents.status', '=', $args['status']);
            })
            ->where('product_contents.language_id', '=', $languageId)
            ->select('products.global_title', 'products.page_template', 'products.status as global_status', 'product_contents.*', 'languages.language_code', 'languages.language_name', 'languages.default_locale')
            ->first();
    }

    public static function getBySlug($slug, $languageId = 0, $options = [])
    {
        $options = (array)$options;
        $defaultArgs = [
            'status' => 1,
            'global_status' => 1
        ];
        $args = array_merge($defaultArgs, $options);

        return static::join('product_contents', 'products.id', '=', 'product_contents.product_id')
            ->join('languages', 'languages.id', '=', 'product_contents.language_id')
            ->where('product_contents.slug', '=', $slug)
            ->where(function ($q) use ($args) {
                if ($args['global_status'] != null) $q->where('products.status', '=', $args['global_status']);
                if ($args['status'] != null) $q->where('product_contents.status', '=', $args['status']);
            })
            ->where('product_contents.language_id', '=', $languageId)
            ->select('products.global_title', 'products.page_template', 'products.status as global_status', 'product_contents.*', 'languages.language_code', 'languages.language_name', 'languages.default_locale')
            ->first();
    }

    public static function getContentById($id, $languageId = 0)
    {
        return ProductContent::getBy([
            'product_id' => $id,
            'language_id' => $languageId
        ]);
    }

    public static function getByCategory($id, $languageId, $otherFields = [], $order = null, $perPage = 0, $select = null)
    {
        $items = Product::join('product_contents', 'products.id', '=', 'product_contents.product_id')
            ->join('languages', 'languages.id', '=', 'product_contents.language_id')
            ->join('product_categories_products', 'product_categories_products.product_id', '=', 'products.id')
            ->join('product_categories', 'product_categories.id', '=', 'product_categories_products.category_id')
            ->groupBy('products.id')
            ->where([
                'product_categories.id' => $id,
                'product_contents.language_id' => $languageId,
            ]);
        foreach ($otherFields as $key => $row) {
            $items = $items->where(function ($q) use ($key, $row) {

                if ($row['compare'] == 'LIKE') {
                    $q->where($key, $row['compare'], '%' . $row['value'] . '%');
                } else {
                    $q->where($key, $row['compare'], $row['value']);
                }
            });
        }
        if ($order && is_array($order)) {
            foreach ($order as $key => $value) {
                $items = $items->orderBy($key, $value);
            }
        }
        if ($select && sizeof($select) > 0) {
            $items = $items->select($select);
        }
        if ($perPage > 0) return $items->paginate($perPage);
        return $items->get();
    }

    public static function getNoContentByCategory($id, $otherFields = [], $order = null, $perPage = 0, $select = null)
    {
        $items = Product::join('product_categories_products', 'product_categories_products.product_id', '=', 'products.id')
            ->join('product_categories', 'product_categories.id', '=', 'product_categories_products.category_id')
            ->groupBy('products.id')
            ->where([
                'product_categories.id' => $id,
            ]);
        foreach ($otherFields as $key => $row) {
            $items = $items->where(function ($q) use ($key, $row) {

                if ($row['compare'] == 'LIKE') {
                    $q->where($key, $row['compare'], '%' . $row['value'] . '%');
                } else {
                    $q->where($key, $row['compare'], $row['value']);
                }
            });
        }
        if ($order && is_array($order)) {
            foreach ($order as $key => $value) {
                $items = $items->orderBy($key, $value);
            }
        }
        if ($select && sizeof($select) > 0) {
            $items = $items->select($select);
        }
        if ($perPage > 0) return $items->paginate($perPage);
        return $items->get();
    }

    public static function getWithContent($fields = [], $order = null, $multiple = false, $perPage = 0)
    {
        $fields = (array)$fields;

        $obj = static::join('product_contents', 'products.id', '=', 'product_contents.page_id')
            ->join('languages', 'languages.id', '=', 'product_contents.language_id');
        if ($fields && is_array($fields)) {
            foreach ($fields as $key => $row) {
                $obj = $obj->where(function ($q) use ($key, $row) {

                    if ($row['compare'] == 'LIKE') {
                        $q->where($key, $row['compare'], '%' . $row['value'] . '%');
                    } else {
                        $q->where($key, $row['compare'], $row['value']);
                    }
                });
            }
        }
        if ($order && is_array($order)) {
            foreach ($order as $key => $value) {
                $obj = $obj->orderBy($key, $value);
            }
        }
        $obj = $obj->groupBy('products.id')
            ->select('products.status as global_status', 'products.page_template', 'products.global_title', 'product_contents.*', 'languages.language_code', 'languages.language_name', 'languages.default_locale');

        if ($multiple) {
            if ($perPage > 0) return $obj->paginate($perPage);
            return $obj->get();
        }
        return $obj->first();
    }
}