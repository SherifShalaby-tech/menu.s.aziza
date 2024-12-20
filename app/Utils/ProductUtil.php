<?php

namespace App\Utils;

use App\Models\AddStockLine;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ConsumptionProduct;
use App\Models\Customer;
use App\Models\EarningOfPoint;
use App\Models\Product;
use App\Models\ProductClass;
use App\Models\ProductSize;
use App\Models\ProductStore;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReturnLine;
use App\Models\RedemptionOfPoint;
use App\Models\RemoveStockLine;
use App\Models\SalesPromotion;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransferLine;
use App\Models\Variation;
use App\Utils\Util;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductUtil extends Util
{
    protected $commonUtil;
    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }
    /**
     * Generates product sku
     *
     * @param string $string
     *
     * @return generated sku (string)
     */
    public function generateProductSku($string)
    {
        $sku_prefix = '';

        return $sku_prefix . str_pad($string, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generated SKU based on the barcode type.
     *
     * @param string $sku
     * @param string $c
     * @param string $barcode_type
     *
     * @return void
     */
    public function generateSubSku($sku, $c, $barcode_type = 'C128')
    {
        $sub_sku = $sku . $c;

        if (in_array($barcode_type, ['C128', 'C39'])) {
            $sub_sku = $sku . '-' . $c;
        }

        return $sub_sku;
    }

    public function generateSku($name, $number = 1)
    {
        $name_array = explode(" ", $name);
        $sku = '';
        foreach ($name_array as $w) {
            if (!empty($w)) {
                if (!preg_match('/[^A-Za-z0-9]/', $w)) {
                    $sku .= $w[0];
                }
            }
        }
        $sku = $sku . '-' . $number;
        $sku_exist = Product::where('sku', $sku)->exists();

        if ($sku_exist) {
            return $this->generateSku($name, $number + 1);
        } else {
            return $sku;
        }
    }


    /**
     * create or update product variation data
     *
     * @param object $product
     * @param object $request
     * @return boolean
     */
    public function createOrUpdateVariations($product, $variations)
    {
        $keey_variations = [];
        if (!empty($variations)) {
            foreach ($variations as $v) {
                $c = Variation::where('product_id', $product->id)
                    ->count() + 1;
                if ($v['name'] == 'Default') {
                    $sub_sku = $product->sku;
                } else {
                    $sub_sku = !empty($v['sub_sku']) ? $v['sub_sku'] : $this->generateSubSku($product->sku, $c, 'C128');
                }

                if (!empty($v['id']) || !empty($v['pos_model_id'])) {
                    $v['default_purchase_price'] = isset($v['default_purchase_price'])? (float)$v['default_purchase_price']:0;
                    $v['default_sell_price'] = (float)$v['default_sell_price'];
                    if (!empty($v['pos_model_id'])) {
                        $variation = Variation::where('pos_model_id', $v['pos_model_id'])->first();
                        if (empty($variation)) {
                            $variation = new Variation();
                        }
                    } else {
                        $variation = Variation::find($v['id']);
                    }
                    $variation->name = $v['name'];
                    $variation->product_id = $product->id;
                    $variation->sub_sku = $sub_sku;
                    $variation->size_id = $v['size_id'] ?? null;
                    $variation->default_purchase_price = !empty($v['default_purchase_price']) ? $this->num_uf($v['default_purchase_price']) : $this->num_uf($product->purchase_price);
                    $variation->default_sell_price = !empty($v['default_sell_price']) ? $this->num_uf($v['default_sell_price']) : $this->num_uf($product->sell_price);
                    if(!env('ENABLE_POS_SYNC')){
                        $variation->pos_model_id = $v['pos_model_id'] ?? null;
                    }
                    $variation->save();

                    $keey_variations[] = $variation->id;
                } else {
                    $variation_data['name'] = $v['name'];
                    $variation_data['product_id'] = $product->id;
                    $variation_data['sub_sku'] = !empty($v['sub_sku']) ? $v['sub_sku'] : $this->generateSubSku($product->sku, $c, 'C128');
                    $variation_data['size_id'] = $v['size_id'] ?? null;
                    $variation_data['default_purchase_price'] = !empty($v['default_purchase_price']) ? $this->num_uf($v['default_purchase_price']) : $this->num_uf($product->purchase_price);
                    $variation_data['default_sell_price'] = !empty($v['default_sell_price']) ? $this->num_uf($v['default_sell_price']) : $this->num_uf($product->sell_price);
                    $variation_data['is_dummy'] = 0;
                    if(!env('ENABLE_POS_SYNC')){
                        $variation_data['pos_model_id'] = $v['pos_model_id'] ?? null;
                    }
                    $variation = Variation::create($variation_data);
                    $keey_variations[] = $variation->id;
                }
            }
        } else {
            $variation_data['name'] = 'Default';
            $variation_data['product_id'] = $product->id;
            $variation_data['sub_sku'] = $product->sku;
            $variation_data['size_id'] = !empty($product->multiple_sizes) ? $product->multiple_sizes[0] : null;
            $variation_data['is_dummy'] = 1;
            $variation_data['default_purchase_price'] = isset($product->purchase_price)?$this->num_uf($product->purchase_price):0;
            $variation_data['default_sell_price'] = $this->num_uf($product->sell_price);
            $variation = Variation::create($variation_data);
            $keey_variations[] = $variation->id;
            
        }

        if (!empty($keey_variations)) {
            //delete the variation removed by user
            Variation::where('product_id', $product->id)->whereNotIn('id', $keey_variations)->delete();
        }

        return true;
    }

    public function createOrUpdateProductSizes($product, $sizes)
    {
        $key_sizes = [];
        if (!empty($sizes)) {
            foreach ($sizes as $s) {
                $product_sizes = ProductSize::where('size_id',$s['size_id'])->where('product_id',$product->id)->first();

                if (!empty($s['id']) && !empty($s['size_id'])) {
                    if(!empty($product_sizes )){
                    $product_sizes->size_id = $s['size_id'];
                    $product_sizes->product_id = $product->id;
                    $product_sizes->sell_price = !empty($s['sell_price']) ? $this->num_uf($s['sell_price']) : $this->num_uf($product_sizes->sell_price);
                    $product_sizes->purchase_price = !empty($s['purchase_price']) ? $this->num_uf($s['purchase_price']) : $this->num_uf($product_sizes->purchase_price);
                
                    $product_sizes->update();

                    $key_sizes[] = $product_sizes->id;
                    }else{
                    $size_data['product_id'] = $product->id;
                    $size_data['size_id'] = $s['size_id'];
                    $size_data['purchase_price'] = $s['purchase_price'];
                    $size_data['sell_price'] = $s['sell_price'];
                 
                    $size = ProductSize::create($size_data);
                    $key_sizes[] = $size->id;
                    }

                } else {
            
                    $size_data['product_id'] = $product->id;
                    $size_data['size_id'] = $s['size_id'];
                    $size_data['purchase_price'] = $s['purchase_price'];
                    $size_data['sell_price'] = $s['sell_price'];
                    $size = ProductSize::create($size_data);
                    $key_sizes[] = $size->id;
                }
            }
        }
   

        if (!empty($key_sizes)) {
            //delete the size product removed by user
            ProductSize::where('product_id', $product->id)->whereNotIn('id', $key_sizes)->delete();
        }

        return true;
    }
    // public function createOrUpdateProductSizes($product, $sizes)
    // {
    //     $key_sizes = [];
    //     if (!empty($sizes)) {
    //         foreach ($sizes as $s) {

    //             if (!empty($s['id']) && !empty($s['size_id'])) {
    //                 $product_sizes = ProductSize::where('size_id',$s['id'])->where('product_id',$product->id)->first();
    //                 $product_sizes->size_id = $s['size_id'];
    //                 $product_sizes->product_id = $product->id;
    //                 $product_sizes->discount_type = !empty($s['discount_type'])?$s['discount_type'] : $product_sizes->discount_type;
    //                 $product_sizes->sell_price = !empty($s['sell_price']) ? $this->num_uf($s['sell_price']) : $this->num_uf($product_sizes->sell_price);
    //                 $product_sizes->purchase_price = !empty($s['purchase_price']) ? $this->num_uf($s['purchase_price']) : $this->num_uf($product_sizes->purchase_price);
    //                 $product_sizes->discount = !empty($s['discount'])?$s['discount'] : $product_sizes->discount;
    //                 $product_sizes->discount_start_date = !empty($s['discount_start_date'])?$s['discount_start_date'] : $product_sizes->discount_start_date;
    //                 $product_sizes->discount_end_date = !empty($s['discount_end_date'])?$s['discount_end_date'] : $product_sizes->discount_end_date;
    //                 $product_sizes->active = !empty($s['active'])?$s['active'] : $product_sizes->active;
                    
    //                 $product_sizes->update();

    //                 $key_sizes[] = $product_sizes->id;
    //             } else {
            
    //                 $size_data['product_id'] = $product->id;
    //                 $size_data['size_id'] = $s['size_id'];
    //                 $size_data['discount'] = $s['discount'];
    //                 $size_data['purchase_price'] = $s['purchase_price'];
    //                 $size_data['sell_price'] = $s['sell_price'];
    //                 $size_data['discount_type'] = $s['discount_type'];
    //                 $size_data['discount_start_date'] = !empty($data['discount_start_date']) ? $this->commonUtil->uf_date($data['discount_start_date']) : null;
    //                 $size_data['discount_end_date'] = !empty($data['discount_end_date']) ? $this->commonUtil->uf_date($data['discount_end_date']) : null;
    //                 $size_data['active'] = !empty($data['active']) ? 1 : 0;
                
    //                 $size = ProductSize::create($size_data);
    //                 $key_sizes[] = $size->id;
    //             }
    //         }
    //     }
    //     //  else{
    //     //     $size_data['name'] = 'Default';
    //     //     $size_data['product_id'] = $product->id;
    //     //     $size_data['sub_sku'] = $product->sku;
    //     //     $size_data['size_id'] = !empty($product->multiple_sizes) ? $product->multiple_sizes[0] : null;
    //     //     $size_data['is_dummy'] = 1;
    //     //     $size_data['default_purchase_price'] = $this->num_uf($product->purchase_price);
    //     //     $size_data['default_sell_price'] = $this->num_uf($product->sell_price);
    //     //     $variation = Variation::create($size_data);
    //     //     $keey_variations[] = $variation->id;
    //     // }

    //     if (!empty($key_sizes)) {
    //         //delete the size product removed by user
    //         ProductSize::where('product_id', $product->id)->whereNotIn('id', $key_sizes)->delete();
    //     }

    //     return true;
    // }
    /**
     * Get all details for a product from its variation id
     *
     * @param int $variation_id
     * @param int $store_id
     * @param bool $check_qty (If false qty_available is not checked)
     *
     * @return object
     */
    public function getDetailsFromVariation($variation_id,  $store_id = null, $check_qty = true)
    {
        $query = Variation::join('products AS p', 'variations.product_id', '=', 'p.id')
            ->leftjoin('product_stores AS ps', 'variations.id', '=', 'ps.variation_id')

            ->where('variations.id', $variation_id);


        if (!empty($store_id) && $check_qty) {
            //Check for enable stock, if enabled check for store id.
            $query->where(function ($query) use ($store_id) {
                $query->where('ps.store_id', $store_id);
            });
        }

        $product = $query->select(
            DB::raw("IF(variations.is_dummy = 0, CONCAT(p.name,
                    ' (', variations.name, ':',variations.name, ')'), p.name) AS product_name"),
            'p.id as product_id',
            'p.sell_price',
            'p.type as product_type',
            'p.name as product_actual_name',
            'variations.name as product_variation_name',
            'variations.is_dummy as is_dummy',
            'variations.name as variation_name',
            'variations.sub_sku',
            'p.barcode_type',
            'ps.qty_available',
            'variations.default_sell_price',
            'variations.id as variation_id',
        )
            ->first();

        return $product;
    }


    public function getProductDetailsUsingArrayIds($array, $store_ids = null)
    {
        $query = Product::leftjoin('variations', 'products.id', 'variations.product_id')
            ->leftjoin('product_stores', 'variations.id', 'product_stores.variation_id');

        if (!empty($store_ids)) {
            $query->whereIn('product_stores.store_id', $store_ids);
        }
        $query->whereIn('products.id', $array)
            ->select(
                'products.*',
                DB::raw('SUM(product_stores.qty_available) as current_stock'),
                DB::raw("(SELECT transaction_date FROM transactions LEFT JOIN add_stock_lines ON transactions.id=add_stock_lines.transaction_id WHERE add_stock_lines.product_id=products.id ORDER BY transaction_date DESC LIMIT 1) as date_of_purchase")
            )
            ->groupBy('products.id');

        $products = $query->get();

        return $products;
    }

    /**
     * extract products using product tree selection
     *
     * @param array $data_selected
     * @return array
     */
    public function extractProductIdsfromProductTree($data_selected)
    {
        $product_ids = [];

        if (!empty($data_selected['product_selected'])) {
            $p = array_values(Product::whereIn('id', $data_selected['product_selected'])->select('id')->pluck('id')->toArray());
            $product_ids = array_merge($product_ids, $p);
        }

        $product_ids  = array_unique($product_ids);

        return (array)$product_ids;
    }

    public function getCorrespondingProductIds($product_ids)
    {
        $array = [];

        foreach ($product_ids as $product_id) {
            $product = Product::where('pos_model_id', $product_id)->first();
            if (!empty($product)) {
                $array[] = (string) $product->id;
            }
        }

        return $array;
    }
    public function getCorrespondingProductIdsReverse($product_ids)
    {
        $array = [];

        foreach ($product_ids as $product_id) {
            $product = Product::where('id', $product_id)->first();
            if (!empty($product)) {
                $array[] = (int) $product->pos_model_id;
            }
        }

        return $array;
    }
}
