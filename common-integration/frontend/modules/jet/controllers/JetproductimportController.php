<?php
namespace frontend\modules\jet\controllers;

use frontend\modules\jet\components\ShopifyClientHelper;
use frontend\modules\jet\components\Jetproductinfo;
use frontend\modules\jet\components\Data;
use Yii;
use yii\web\Controller;
use yii\filters\VerbFilter;

/**
 * JetproductController implements the CRUD actions for JetProduct model.
 */
class JetproductimportController extends Controller
{

    protected $connection;
    const MAX_CUSTOM_PRODUCT_IMPORT_PER_REQUEST = 50;
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'delete' => ['post'],
                ],
            ],
        ];
    }
    public function beforeAction($action){
        Yii::$app->request->enableCsrfValidation = false;
        return parent::beforeAction($action);
    }
    /**
     * Lists all JetProduct models.
     * @return mixed
     */
    
    public function actionImport()
    {
        //$this->layout = 'main2';
        return $this->render('import');
    }

    public function actionGettotaldetails()
    {
        $result = [];
        $session = Yii::$app->session;
        $merchantId = isset($_REQUEST['merchant_id'])?$_REQUEST['merchant_id']:"";
        $non_sku_total = isset($_REQUEST['non_sku_total'])?$_REQUEST['non_sku_total']:0;
        $non_type_total = isset($_REQUEST['non_type_total'])?$_REQUEST['non_type_total']:0;
        $select = isset($_REQUEST['select'])?$_REQUEST['select']:"";
        $limit = isset($_REQUEST['limit'])?$_REQUEST['limit']:250;
        $page = isset($_REQUEST['page'])?$_REQUEST['page']:0;
        $select = $select =='custom'?'any':$select;
        $allowedSelectValues = ['any', 'published'];
        if($merchantId && in_array($select, $allowedSelectValues))
        {
            //$connection = Yii::$app->getDb();
            define("MERCHANT_ID",Yii::$app->user->identity->id);
            define("SHOP",Yii::$app->user->identity->username);
            define("TOKEN",Yii::$app->user->identity->auth_key);
            $merchant_id = MERCHANT_ID?:$merchantId;
            $shopname = SHOP;
            $token = TOKEN;
            $countProducts = $pages = $nonSkuCount = $totalSkuCount = $nonProductType = $currentTotalProd = 0;
            $index = 1;
            
            $sameSku ="";
            $notSku ="";
            $notProductType="";
            $sameSkuArray=[];
            if(isset($session[$merchant_id.'samesku'])){
                $sameSkuArray=$session[$merchant_id.'samesku'];
            }
            $notSkuArray=[];
            $notProductTypeArray=[];
            $sc = new ShopifyClientHelper($shopname, $token, PUBLIC_KEY, PRIVATE_KEY);
            $countProducts = $sc->call('GET', '/admin/products/count.json', ['published_status' => $select]);
            if(isset($countProducts['errors'])){
                $result['err'] = $countProducts['errors'];
                return json_encode($result);
            }
            //save import status published/any/custom
            Data::jetsaveConfigValue($merchant_id, 'import_status', $select);
            $productData = [];
            $pages = ceil($countProducts/$limit);
            if($page<=$pages)
            {
                $products = "";
                $products = $sc->call('GET', '/admin/products.json', ['fields' => "id, title, variants, product_type",'published_status' => $select,'limit' => $limit,'page' => $page]);
                if(isset($products['errors'])){
                    $result['err'] = $products['errors'];
                    return json_encode($result);
                }
                $currentTotalProd = count($products);
                foreach($products as $prod) 
                {
                    if(trim($prod['product_type']) == '')
                    {
                        $notProductType .= ','.$prod['id'];
                        $nonProductType ++;
                        continue;
                    }
                    $fg = true;
                    $varientArray = $prod['variants'];
                    usort($varientArray, array($this, "interchangeArray"));
                    $prod['variants'] = $varientArray;
                    foreach($prod['variants'] as $variant) 
                    {
                        if($variant['position'] == '1' && trim($variant['sku'])=="") {
                            $nonSkuCount ++;
                            $notSku .= ','.$prod['id'];
                            $fg = false;
                            break;
                        }
                        $totalSkuCount ++;
                        if(empty($sameSkuArray))
                        {
                            $skuKey = Data::getKey($variant['sku']);
                            $sameSkuArray[$skuKey]='1';
                        }
                        else
                        {
                            if(isset($sameSkuArray[$variant['sku']]))
                            {
                                $sameSku .= ','.$prod['id'];
                                $fg = false;
                                break;
                            }
                            else{
                                $skuKey = Data::getKey($variant['sku']);
                                $sameSkuArray[$skuKey]='1';
                            }
                        }
                    }
                    if(!$fg){
                        continue;
                    }
                    $productData[$prod['id']] = $prod;
                }
                    
            }
            //var_dump($sameSku);
            if ($totalSkuCount>10000)
            {
                $result['err']="You have more than 10K products (with variants), please contact us for next step <a href='mailto:shopify@cedcommerce.com?subject=Need help in product import (shopify-jet)'> Contact Us</a>";
                return json_encode($result); 
            }
            
            $result ['total'] = $countProducts;    
            $result ['non_sku'] = $nonSkuCount;
            $result ['total_sku_count'] = $totalSkuCount;
            $result ['ready'] = $currentTotalProd - ($nonSkuCount + $nonProductType); 
            $result ['non_type'] = $nonProductType;
            $result ['csrf'] = Yii::$app->request->getCsrfToken();
            $result ['products'] = $productData;
            $non_sku_total += $nonSkuCount;
            $non_type_total += $nonProductType;
            $filePath = \Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id;
            if (!file_exists($filePath)) {
                mkdir($filePath,0775, true);
            }
            $base_path=$filePath.'/samesku.txt';
            $file = fopen($base_path,"w");
            fwrite($file,$sameSku);
            fclose($file);
            $base_path=$filePath.'/notsku.txt';
            $file = fopen($base_path,"w");
            fwrite($file,$notSku);
            fclose($file);
            $base_path=$filePath.'/notProductType.txt';
            $file = fopen($base_path,"w");
            fwrite($file,$notProductType);
            fclose($file);
            if(count($sameSkuArray)>0){
                $session[$merchant_id.'samesku']=$sameSkuArray;
            }
            if($page==$pages)
            {
                $inserted="";
                $resultSQL = [];
                $resultSQL = Data::sqlRecords("SELECT `merchant_id` FROM `insert_product` WHERE merchant_id='".$merchant_id."' LIMIT 0,1","one","select");
                if(empty($resultSQL))
                {
                   $queryObj = "";
                   $query = 'INSERT INTO `insert_product`
                                (
                                    `merchant_id`,
                                    `product_count`,
                                    `not_sku`,
                                    `status`,
                                    `total_product`
                                )
                                VALUES(
                                    "'.$merchant_id.'",
                                    "'.($countProducts - ($non_sku_total+$non_type_total)).'",
                                    "'.($non_sku_total+$non_type_total).'",
                                    "inserted",
                                    "'.$countProducts.'"
                                )';
                    Data::sqlRecords($query,null,'insert');
                }else
                {
                    $updateQuery = "UPDATE `insert_product` SET `product_count`='".($countProducts - ($non_sku_total+$non_type_total))."' ,`total_product`='".$countProducts."', `not_sku`='".($non_sku_total+$non_type_total)."' WHERE merchant_id='".$merchant_id."'";
                    Data::sqlRecords($updateQuery,null,'update');
                } 
            }            
        }
        return json_encode($result);
    }
    
    public function interchangeArray($a , $b){
        if ($a["position"] == $b["position"]) {
            return 0;
        }
        return ($a["position"] < $b["position"]) ? -1 : 1;
    }
    
    public function actionBatchimport(){

        $session = Yii::$app->session;
        $sameSkuArray = [];
        $custom_sku = Yii::$app->request->post('customsku');
        $index = Yii::$app->request->post('index');
        $select = Yii::$app->request->post('select');
        $merchant_id = Yii::$app->request->post('merchant_id');
        if(isset($session[$merchant_id.'batchsamesku'])){
            $sameSkuArray=$session[$merchant_id.'batchsamesku'];
        }

        $limit = isset($_REQUEST['limit'])?$_REQUEST['limit']:250;
        try
        {
            $sc = "";
            //$connection = Yii::$app->getDb();
            define("MERCHANT_ID",Yii::$app->user->identity->id);
            define("SHOP",Yii::$app->user->identity->username);
            define("TOKEN",Yii::$app->user->identity->auth_key);
            $shopname = SHOP;
            $token = TOKEN;
            
            $sc = new ShopifyClientHelper($shopname, $token, PUBLIC_KEY, PRIVATE_KEY);
            $products = $sc->call('GET', '/admin/products.json', ['published_status' => $select, 'limit' => $limit, 'page' => $index]);
            if(isset($products['errors'])){
                $returnArr['error'] = $products['errors'];
                return json_encode($returnArr);
            }
            $readyCount = 0;
            $notSku = 0;
            $notType = 0;
            $sameskucount = 0;
            if($products)
            {
                foreach ($products as $prod){
                    $noSkuFlag = 0;
                    if(trim($prod['product_type'])==''){
                        $notType ++;
                        continue;
                    }
                    $value = $prod;
                    $varientArray = $prod['variants'];
                    usort($varientArray, array($this, "interchangeArray"));
                    $value['variants'] = $varientArray;
//                    var_dump($value['variants']);die;
                    foreach($value['variants'] as $key => $variant) 
                    {
                        if($variant['position'] == '1' && trim($variant['sku'])=="") {
                            if($custom_sku=='false'){
                                $noSkuFlag = 1;
                                $notSku ++;
                                break;
                            }
                            else{
                                $value['variants'][$key]['sku']=Data::getCustomsku($variant['id']);

                            }
                        }
                        if(empty($sameSkuArray)){
                            $skuKey = Data::getKey($variant['sku']);
                            $sameSkuArray[$skuKey]='1';
                        }
                        else{
                            if(isset($sameSkuArray[$variant['sku']])){
                                if($custom_sku=='false'){
                                    $noSkuFlag = 1;
                                    $sameskucount ++;
                                    break;
                                }
                               else{
                                $value['variants'][$key]['sku']=Data::getCustomsku($variant['id']);
                               }
                                
                            }
                            else{
                                $skuKey = Data::getKey($variant['sku']);
                                $sameSkuArray[$skuKey]='1';
                            }
                        }
                    }
                    if(!$noSkuFlag){
                        $readyCount ++;
                        Jetproductinfo::saveNewRecords($value, $merchant_id, $select);
                    }
                    
                }
            }
          
        }
        catch (Exception $e){
            return $returnArr['err'] = $e->getMessage();
        }
        $returnArr['success']['count'] = $readyCount;
        $returnArr['success']['not_sku'] = $notSku;
        $returnArr['success']['not_type'] = $notType;
        $returnArr['success']['sameskucount'] = $sameskucount;
        
        if(count($sameSkuArray)>0){
            $session[$merchant_id.'batchsamesku']=$sameSkuArray;
        }
        //$connection->close();
        return json_encode($returnArr);
    }

    public function actionCustomImport()
    {
        $shopname=Yii::$app->user->identity->username;
        $token=Yii::$app->user->identity->auth_key;
        $sc = new ShopifyClientHelper($shopname, $token, PUBLIC_KEY, PRIVATE_KEY);
        $productIds = Yii::$app->request->post('product_ids',false);
        $page = Yii::$app->request->post('page', false);
        if($productIds && $page!==false)
        {
            try
            {
                $merchant_id = Yii::$app->user->identity->id;
                
                $max = self::MAX_CUSTOM_PRODUCT_IMPORT_PER_REQUEST;
                $ids = array_chunk($productIds, $max);
                //foreach ($ids as $id) 
                if(isset($ids[$page]))
                {
                    $id =  $ids[$page];

                    $product_ids = implode(',', $id);
                    $products = $sc->call('GET', '/admin/products.json?ids='.$product_ids, array());

                    if(isset($products['errors'])){
                        $returnArr['error'] = $products['errors'];
                        return json_encode($returnArr);
                    }

                    $readyCount = 0;
                    $notSku = 0;
                    $notType = 0;
                    if($products){
                        foreach ($products as $prod){
                            $noSkuFlag = 0;
                            if(trim($prod['product_type'])==''){
                                $notType ++;
                                continue;
                            }
                            $value = $prod;
                            $varientArray = $prod['variants'];
                            usort($varientArray, array($this, "interchangeArray"));
                            $value['variants'] = $varientArray;
                            foreach($value['variants'] as $variant) {
                                if($variant['position'] == '1' && trim($variant['sku'])=="") {
                                    $noSkuFlag = 1;
                                    $notSku ++;
                                    break;
                                }
                            }
                            
                            if(!$noSkuFlag){
                                $readyCount ++;
                                Jetproductinfo::saveNewRecords($value, $merchant_id, false);
                            }
                            
                        }
                    }
                }
            }
            catch (ShopifyApiException $e){
                return $returnArr['error'] = $e->getMessage();
            }
            catch (ShopifyCurlException $e){
                return $returnArr['error'] = $e->getMessage();
            }

            $returnArr['success']['count'] = $readyCount;
            $returnArr['success']['not_sku'] = $notSku;
            $returnArr['success']['not_type'] = $notType;
            
            return json_encode($returnArr);
        }
        else
        {
            return json_encode(['error'=>'No product selected for import.']);
        }
    }
     /**
     * unlink file 
     * @return boolean
     */
    public function actionFileexist()
    {
        $merchant_id=Yii::$app->user->identity->id;
        $session = Yii::$app->session;
        if(isset($session[$merchant_id.'samesku'])){
            unset($session[$merchant_id.'samesku']);
        }
        if(isset($session[$merchant_id.'batchsamesku'])){
            unset($session[$merchant_id.'batchsamesku']);
        }
        if (file_exists(\Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id.'/samesku.txt')) 
        {
            $files = glob(\Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id.'/*'); // get all file names
            foreach($files as $file){ // iterate files
              if(is_file($file))
                unlink($file); // delete file
            }
        }
        return false;

    }
    /**
     * get importproduct.txt file content
     * @return string data
    */
    public function actionGetfilecontent()
    {
         $merchant_id=Yii::$app->user->identity->id;
        if (file_exists(\Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id.'/samesku.txt')) {
            return file_get_contents(\Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id.'/samesku.txt');
        }
        return false;

    }

    /**
     * get errored product info on shopify
     * @return array
    */
    public function actionViewerroredproduct($file)
    {
        //$this->layout = 'blank';
        $merchant_id=Yii::$app->user->identity->id;
        $info_array=[];
        $content = file_get_contents(\Yii::getAlias('@webroot').'/var/jet/importproduct/'.$merchant_id.'/'.$file.'.txt');
        $real_content = trim($content,',');
        $real_array = explode(',', $real_content);
        $valid_array = array_chunk($real_array, 49);
        $return_array = [];
        foreach ($valid_array as $key => $value) {
            $result_array[] = implode(',',$value);
        }
        return json_encode($result_array);
    }
}
