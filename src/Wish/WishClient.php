<?php
/**
 * Copyright 2014 Wish.com, ContextLogic or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at 
 * 
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Wish;

use Wish\Exception\UnauthorizedRequestException;
use Wish\Exception\ServiceResponseException;
use Wish\Exception\OrderAlreadyFulfilledException;
use Wish\Model\WishProduct;
use Wish\Model\WishProductVariation;
use Wish\Model\WishOrder;
use Wish\Model\WishTracker;
use Wish\Model\WishReason;
use Wish\Model\WishAddress;
use Wish\Model\WishTicket;


class WishClient{
  private $session;
  private $products;
  private $orders;

  const LIMIT = 50;

  public function __construct($access_token,$session_type='prod',$merchant_id=null){

    $this->session = new WishSession($access_token,$session_type,$merchant_id);

  }

  public function getResponse($type,$path,$params=array()){

    $request = new WishRequest($this->session,$type,$path,$params);
    $response = $request->execute();
    if($response->getStatusCode()==4000){
      throw new UnauthorizedRequestException("Unauthorized access",
        $request,
        $response);
    }
    if($response->getStatusCode()==1015){
      throw new UnauthorizedRequestException("Access Token expired",
        $request,
        $response);
    }
    if($response->getStatusCode()==1016){
      throw new UnauthorizedRequestException("Access Token revoked",
        $request,
        $response);
    }
    if($response->getStatusCode()==1000){
      throw new ServiceResponseException("Invalid parameter",
        $request,
        $response);
    }
    if($response->getStatusCode()==1002){
      throw new OrderAlreadyFulfilledException("Order has been fulfilled",
        $request,
        $response);
    }
    if($response->getStatusCode()!=0){
      throw new ServiceResponseException("Unknown error",
        $request,
        $response);
    }
    return $response;

  }

  public function getResponseIter($method,$uri,$getClass,$params=array()){
    $start = 0;
    $params['limit'] = static::LIMIT;
    $class_arr = array();
    do{
      $params['start']=$start;
      $response = $this->getResponse($method,$uri,$params);

      $responseData = $response->getData();
      if (!empty($responseData)){
          foreach($responseData as $class_raw){
              $class_arr[] = new $getClass($class_raw);
          }
      }
      $start += static::LIMIT;
    }while($response->hasMore());
    return $class_arr;
  }

  public function authTest(){
    $response = $this->getResponse('GET','auth_test');
    return "success";

  }

  // PRODUCT

  public function getProductById($id){
    $params = array('id'=>$id);
    $response = $this->getResponse('GET','product',$params);
    return new WishProduct($response->getData());
  }

  public function getProductByParentSku($parentSku){
    $params = array('parent_sku'=>$parentSku);
    $response = $this->getResponse('GET','product',$params);
    return new WishProduct($response->getData());
  }

  public function createProduct($object){
    $response = $this->getResponse('POST','product/add',$object);
    return new WishProduct($response->getData());
  }

  public function updateProduct(WishProduct $product){

    $params = $product->getParams(array(
      'id',
      'name',
      'description',
      'tags',
      'brand',
      'landing_page_url',
      'upc',
      'main_image',
      'extra_images'));

    $response = $this->getResponse('POST','product/update',$params);

    return "success";
  }

  public function enableProduct(WishProduct $product){
    $this->enableProductById($product->id);
  }

  public function enableProductById($id){
    $params = array('id'=>$id);
    $response = $this->getResponse('POST','product/enable',$params);
    return "success";
  }

  public function disableProduct(WishProduct $product){
    $this->disableProductById($product->id);
  }

  public function disableProductById($id){
    $params = array('id'=>$id);
    $response = $this->getResponse('POST','product/disable',$params);
    return "success";
  }

  public function getAllProducts(){
    return $this->getResponseIter(
      'GET',
      'product/multi-get',
      "Wish\Model\WishProduct");
  }

  public function removeExtraImages(WishProduct $product){
    return $this->removeExtraImagesById($product->id);
  }

  public function removeExtraImagesById($id){
    $params = array('id'=>$id);
    $response = $this->getResponse('POST','product/remove-extra-images',$params);
    return "success";
  }

  public function updateShippingById($id,$country,$price, $wishExpress = null){
    $params = array('id'=>$id,'country'=>$country,'price'=>$price);

    if (!is_null($wishExpress)) {
        $params['wish_express'] = empty($wishExpress) ? 'false' : 'true';
    }

    $response = $this->getResponse('POST','product/update-shipping',$params);
    return "success";
  }

    /**
     *
     * @comment $countryPriceList example: ['US' => 10.99, 'UK' => 9.99]
     *
     * @param $id
     * @param array $countryPriceList
     * @param array $disabledCountries
     * @param array $wishExpressAddCountries
     * @param array $wishExpressRemoveCountries
     * @param null $warehouseName
     * @param null $defaultShippingPrice
     * @return string
     *
     * @see https://merchant.wish.com/documentation/api/v2#update-multi-shipping
     */
  public function updateMultiShippingById(
      $id,
      $countryPriceList = [],
      $disabledCountries=[],
      $wishExpressAddCountries=[],
      $wishExpressRemoveCountries=[],
      $warehouseName=null,
      $defaultShippingPrice=null
  ){
      $params = array('id'=>$id);

      if (!empty($countryPriceList)){
          foreach ($countryPriceList as $countryCode => $shippingValue){
              $params[$countryCode] = $shippingValue;
          }
      }

      if (!empty($disabledCountries)){
          $params['disabled_countries'] = implode(',', $disabledCountries);
      }

      if (!empty($wishExpressAddCountries)){
          $params['wish_express_add_countries'] = implode(',', $wishExpressAddCountries);
      }

      if (!empty($wishExpressRemoveCountries)){
          $params['wish_express_remove_countries'] = implode(',', $wishExpressRemoveCountries);
      }

      if (!empty($warehouseName)){
          $params['warehouse_name'] = $warehouseName;
      }

      if (!is_null($defaultShippingPrice)) {
          $params['default_shipping_price'] = $defaultShippingPrice;
      }

      $response = $this->getResponse('POST','product/update-multi-shipping', $params);
      return "success";
  }

  public function getShippingById($id,$country){
    $params = array('id'=>$id,'country'=>$country);
    $response = $this->getResponse(
      'GET',
      'product/get-shipping',
      $params);
    return json_encode($response->getData());
  }

  public function getAllShippingById($id){
    $params = array('id'=>$id);
    $response = $this->getResponse(
      'GET',
      'product/get-all-shipping',
      $params);
    return json_encode($response->getData());
  }

  // PRODUCT VARIATION

  public function createProductVariation($object){
    $response = $this->getResponse('POST','variant/add',$object);
    return new WishProductVariation($response->getData());
  }

  public function getProductVariationBySKU($sku){
    $response = $this->getResponse('GET','variant',array('sku'=>$sku));
    return new WishProductVariation($response->getData());
  }

  public function updateProductVariation(WishProductVariation $var){
    $params = $var->getParams(array(
        'sku',
        'inventory',
        'price',
        'enabled',
        'size',
        'color',
        'msrp',
        'shipping_time',
        'main_image'
      ));
    $response = $this->getResponse('POST','variant/update',$params);
    return "success";
  }

  public function changeProductVariationSKU($sku, $new_sku){
    $params = array('sku'=>$sku, 'new_sku'=>$new_sku);
    $response = $this->getResponse('POST','variant/change-sku',$params);
    return "success";
  }

  public function enableProductVariation(WishProductVariation $var){
    $this->enableProductVariationBySKU($var->sku);
  }
  public function enableProductVariationBySKU($sku){
    $params = array('sku'=>$sku);
    $response = $this->getResponse('POST','variant/enable',$params);
    return "success";
  }

  public function disableProductVariation(WishProductVariation $var){
    $this->disableProductVariationBySKU($var->sku);
  }
  public function disableProductVariationBySKU($sku){
    $params = array('sku'=>$sku);
    $response = $this->getResponse('POST','variant/disable',$params);
    return "success";
  }

  public function updateInventoryBySKU($sku,$newInventory){
    $params = array('sku'=>$sku,'inventory'=>$newInventory);
    $response = $this->getResponse('POST','variant/update-inventory',$params);
    return "success";
  }

  public function getAllProductVariations(){
    return $this->getResponseIter(
      'GET',
      'variant/multi-get',
      "Wish\Model\WishProductVariation");
  }

  // ORDER

  public function getOrderById($id){
    $response = $this->getResponse('GET','order',array('id'=>$id));
    return new WishOrder($response->getData());
  }

  public function getAllChangedOrdersSince($time=null){
    $params = array();
    if($time){
      $params['since']=$time;
    }
    return $this->getResponseIter(
      'GET',
      'order/multi-get',
      "Wish\Model\WishOrder",
      $params);
  }

  public function getAllUnfulfilledOrdersSince($time=null, $limit=null){
    $params = array();
    if($time){
      $params['since']=$time;
    }
    if ($limit) {
        $params['limit']=$limit;
    }
    return $this->getResponseIter(
      'GET',
      'order/get-fulfill',
      "Wish\Model\WishOrder",
      $params);
  }

  public function fulfillOrderById($id,WishTracker $tracking_info){
    $params = $tracking_info->getParams();
    $params['id']=$id;
    $response = $this->getResponse('POST','order/fulfill-one',$params);
    return "success";
  }

  public function fulfillOrder(WishOrder $order, WishTracker $tracking_info){
    return $this->fulfillOrderById($order->order_id,$tracking_info);
  }

  public function refundOrderById($id,$reason,$note=null){
    $params = array(
      'id'=>$id,
      'reason_code'=>$reason);
    if($note){
      $params['reason_note'] = $note;
    }
    $response = $this->getResponse('POST','order/refund',$params);
    return "success";
  }

  public function refundOrder(WishOrder $order,$reason,$note=null){
    return refundOrderById($order->order_id,$reason,$note);
  }

  public function updateTrackingInfo(WishOrder $order,WishTracker $tracker){
    return $this->updateTrackingInfoById($order->order_id,$tracker);
  }

  public function updateTrackingInfoById($id,WishTracker $tracker){
    $params = $tracker->getParams();
    $params['id']=$id;
    $response = $this->getResponse('POST','order/modify-tracking',$params);
    return "success";
  }

  public function updateShippingInfo(WishOrder $order,WishAddres $address){
      return $this->updateShippingInfoById($order->order_id,$address);
  }

  public function updateShippingInfoById($id,WishAddress $address){
    $params = $address->getParams();
    $params['id']=$id;
    $response = $this->getResponse('POST','order/change-shipping',$params);
    return "success";
  }

  // TICKET

  public function getTicketById($id){
    $params['id']=$id;
    $response = $this->getResponse('GET','ticket',$params);
    return new Wishticket($response->getData());
  }

  public function getAllActionRequiredTickets(){
    return $this->getResponseIter(
      'GET',
      'ticket/get-action-required',
      "Wish\Model\WishTicket");
  }

  public function replyToTicketById($id,$reply){
    $params['id']=$id;
    $params['reply']=$reply;
    $response = $this->getResponse('POST','ticket/reply',$params);
    return "success";
  }

  public function closeTicketById($id){
    $params['id']=$id;
    $response = $this->getResponse('POST','ticket/close',$params);
    return "success";
  }

  public function appealTicketById($id){
    $params['id']=$id;
    $response = $this->getResponse('POST','ticket/appeal-to-wish-support',$params);
    return "success";
  }

  public function reOpenTicketById($id,$reply){
    $params['id']=$id;
    $params['reply']=$reply;
    $response = $this->getResponse('POST','ticket/re-open',$params);
    return "success";
  }

  // NOTIFICATION
  public function getAllNotifications(){
    $response = $this->getResponse('GET','noti/fetch-unviewed');
     return $response->getData();
  }

  public function markNotificationAsViewed($id){
    $params['id']=$id;
    $response = $this->getResponse('POST','noti/mark-as-viewed',$params);
    return $response->getData();
  }

  public function getUnviewedNotiCount(){
     $response = $this->getResponse('GET','noti/get-unviewed-count');
     return $response->getData();
  }

  public function getBDAnnouncemtns(){
     $response = $this->getResponse('GET','fetch-bd-announcement');
     return $response->getData();
  }


  public function getSystemUpdatesNotifications(){
     $response = $this->getResponse('GET','fetch-sys-updates-noti');
     return $response->getData();
  }

  public function getInfractionCount(){
     $response = $this->getResponse('GET','count/infractions');
     return $response->getData();
  }

  public function getInfractionLinks(){
     $response = $this->getResponse('GET','get/infractions');
     return $response->getData();
  }


    /**
     * @param string|null $since
     * @param int|null $limit
     * @param string|null $sort
     * @param string|null $warehouse_name
     * @return mixed
     */
  public function productCreateDownloadJob($since = null, $limit = null, $sort = null, $warehouse_name = null)
  {
      $params = array();

      if (!is_null($since)) {
          $params['since'] = $since;
      }

      if (!is_null($limit)) {
          $params['limit'] = $limit;
      }

      if (!is_null($sort)) {
          $params['sort'] = $sort;
      }

      if (!is_null($warehouse_name)) {
          $params['warehouse_name'] = $warehouse_name;
      }

      $response = $this->getResponse('POST','product/create-download-job', $params);
      return $response->getData();
  }

    /**
     * @param int $id
     * @return mixed
     */
  public function productGetDownloadJobStatus($id)
  {
      $params = array();
      $params['job_id'] = $id;

      $response = $this->getResponse('POST','product/get-download-job-status', $params);
      return $response->getData();
  }

    /**
     * @param int $id
     * @return mixed
     */
  public function productCancelDownloadJob($id)
  {
      $params = array();
      $params['job_id'] = $id;

      $response = $this->getResponse('POST','product/cancel-download-job', $params);
      return $response->getData();
  }

    /**
     * @param string|null $start
     * @param string|null $end
     * @param int|null $limit
     * @param string|null $sort
     * @return mixed
     */
  public function orderCreateDownloadJob($start = null, $end = null, $limit = null, $sort = null)
  {
      $params = array();

      if (!is_null($start)) {
          $params['start'] = $start;
      }

      if (!is_null($end)) {
          $params['end'] = $end;
      }

      if (!is_null($limit)) {
          $params['limit'] = $limit;
      }

      if (!is_null($sort)) {
          $params['sort'] = $sort;
      }

      $response = $this->getResponse('POST','order/create-download-job', $params);
      return $response->getData();
  }

    /**
     * @param int $id
     * @return mixed
     */
  public function orderGetDownloadJobStatus($id)
  {
      $params = array();
      $params['job_id'] = $id;

      $response = $this->getResponse('POST','order/get-download-job-status', $params);
      return $response->getData();
  }

    /**
     * @param int $id
     * @return mixed
     */
  public function orderCancelDownloadJob($id)
  {
      $params = array();
      $params['job_id'] = $id;

      $response = $this->getResponse('POST','order/cancel-download-job', $params);
      return $response->getData();
  }
}
