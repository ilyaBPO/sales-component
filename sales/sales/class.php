<?
use \Bitrix\Main\Application;
use \Bitrix\Main\Loader; 

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

class ShopRegCompSimple extends CBitrixComponent {

    /**
     * Проверка наличия модулей требуемых для работы компонента
     * @return bool
     * @throws Exception
     */
    private function _checkModules() {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('sale') || !Loader::includeModule('statistic')) {
            throw new \Exception('Не загружены модули необходимые для работы модуля');
        }

        return true;
    }

    /**
     * Обертка над глобальной переменной
     * @return CAllMain|CMain
     */
    private function _app() {
        global $APPLICATION;
        return $APPLICATION;
    }

    /**
     * Обертка над глобальной переменной
     * @return CAllUser|CUser
     */
    private function _user() {
        global $USER;
        return $USER;
    }

    /**
     * Подготовка параметров компонента
     * @param $arParams
     * @return mixed
     */
    public function onPrepareComponentParams($arParams) {
        $this->arParams = $arParams;
        return $arParams;
    }

    /**
     * Точка входа в компонент
     * Должна содержать только последовательность вызовов вспомогательых ф-ий и минимум логики
     * всю логику стараемся разносить по классам и методам 
     */
    public function executeComponent() {
        $this->_checkModules();
        $this->arResult = $this->getResult();
        $this->includeComponentTemplate();
    }

    
    private function getResult(){

        $products = $this->getUsersProducts();
        $ids = array_keys($products);
        $likes = (isset($this->arParams["SHOW_LIKES"]) && $this->arParams["SHOW_LIKES"] === "Y") ? $this->getLikes($ids) : [];
        $views = (isset($this->arParams["SHOW_VIEWS"]) && $this->arParams["SHOW_VIEWS"] === "Y") ? $this->getViews($products) : [];
        $basketData = $this->getBasketData($ids);
        $userIds = array_values(array_unique(array_column($basketData["ITEMS"], "USER_DATA")));
        $users = $this->getUserData($userIds);
        foreach ($basketData["ITEMS"] as $key => &$value) {
            $value["FIELDS"] = $products[$value["PRODUCT_ID"]];
            if(isset($users[$value["USER_DATA"]])){
                $value["USER_DATA"] = $users[$value["USER_DATA"]];
            }
            if(isset($this->arParams["SHOW_LIKES"]) && $this->arParams["SHOW_LIKES"] === "Y"){
                if(isset($likes[$value["PRODUCT_ID"]])){
                    $value["LIKES"] = $likes[$value["PRODUCT_ID"]];
                }
                else{
                    $value["LIKES"] = 0;
                }
            }
            if(isset($this->arParams["SHOW_VIEWS"]) && $this->arParams["SHOW_VIEWS"] === "Y"){
                $value["VIEWS"] = $views[$value["PRODUCT_ID"]];
            }
        }
        //$basketData['TEST_PARAM'] = $this->arParams;
        return $basketData;
    }

    // Получаем товары пользователя
    private function getUsersProducts(){
        $shop = $this->getUserShop();
        if($shop){
            $rsProducts = CIBlockElement::GetList(
                array("sort"=>"asc"),
                array("IBLOCK_ID"=>1, "PROPERTY_MAGASIN"=>$shop["ID"], "SECTION_ID" => $this->arParams['FILTER']['SECTIONS'], '>=CATALOG_PRICE_1' => $this->arParams['FILTER']['PRICES']["FROM"], '<=CATALOG_PRICE_1' => $this->arParams['FILTER']['PRICES']["TO"]),
                false,
                false,
                array("ID", "DETAIL_PAGE_URL", "NAME", "PREVIEW_PICTURE")
            );
            $products = [];
            while($product = $rsProducts->GetNext()){
                $products[$product["ID"]] = $product;
            }

            return $products;
        }
        else LocalRedirect('/personal/');
    }

    //Получаем элемент магазин из ИБ для текущего пользователя
    private function getUserShop(){
        $rsDataShop = CIBlockElement::GetList(
            array("SORT"=>"ASC"),
            array("PROPERTY_USER"=>$this->_user()->GetID(), "IBLOCK_ID"=>"4", "PROPERTY_DELETED_SHOP"=>"N", "ACTIVE"=>"Y", "PROPERTY_IS_DOCUMENT_SUBSCRIBED_VALUE"=>"да"),
            false,
            false,
            array("*")
        );
        $shop = $rsDataShop->GetNext();
        return $shop;
    }

    private function getBasketData(&$ids){
        $arFilter = array(
            "=PRODUCT_ID" => $ids,
            "!ORDER_ID" => null,
            "ORDER.PAYED" => "Y",
        );
        if(!empty($this->arParams['FILTER']['DATE_FROM'])){
            $arFilter[">=DATE_UPDATE"] = date("d.m.Y", strtotime($this->arParams['FILTER']['DATE_FROM']));
        }
        if(!empty($this->arParams['FILTER']['DATE_TO'])){
            $arFilter["<DATE_UPDATE"] = date("d.m.Y", strtotime($this->arParams['FILTER']['DATE_TO']. " +1 day"));
        }

        if(!empty($this->arParams['PAGER']['PAGE_ELEMENTS'])){
            $arNavigationLimit = $this->arParams['PAGER']['PAGE_ELEMENTS'];
        } else{
            $arNavigationLimit = 6;
        }
        if(!empty($this->arParams['PAGER']['PAGE_ELEMENTS'])){
            $arNavigationOffset = $this->arParams['PAGER']['CUR_PAGE'];
        } else{
            $arNavigationOffset = 1;
        }
        if(!empty($this->arParams['SORT_BY'])){
            $sortBy = $this->arParams['SORT_BY'];
        } else{
            $sortBy = "DATE_UPDATE";
        }
        if(!empty($this->arParams['SORT_ORDER'])){
            $sortOrder = $this->arParams['SORT_ORDER'];
        } else{
            $sortOrder = "desc";
        }

        $sortArray = [$sortBy => $sortOrder];

        // 
        $result = \Bitrix\Sale\Internals\BasketTable::getList(
            array(
                "filter" => $arFilter,
                "select" => array("PRODUCT_ID", "PRICE", "DATE_UPDATE", "DATE_PAYED" => "ORDER.DATE_PAYED", "USER_DATA" => "ORDER.USER_ID"),
                "runtime" => array(
                    "ORDER" => array(
                        'data_type' => '\Bitrix\Sale\Internals\OrderTable',
                        'reference' => array(
                            "=this.ORDER_ID" => "ref.ID"
                        ),
                        "join_type" => "inner"
                    )
                ),
                "order" => $sortArray,
                "limit" => $arNavigationLimit,
                "offset" => ($arNavigationOffset - 1)*$arNavigationLimit,
                "count_total" => true
            )
        );
        $basketData = [
            "ITEMS" => array(), 
            "NAV"=>array(
                "ELEMENT_CNT" => $result->getCount(),
                "PAGE_CURRENT" => $arNavigationOffset,
                "MAX_PAGE" => ceil($result->getCount()/$arNavigationLimit)
            )
        ];
        while($basketEl = $result->fetch()){
            $basketData["ITEMS"][] = $basketEl;
        }
        return $basketData;
    }

    // Получаем данные пользователей по входящему массиву id
    private function getUserData($ids){
        $rsUsers = \Bitrix\Main\UserTable::getList(
            array(
                "select" => array("ID", "UF_PROFILE_NAME"),
                "filter" => array("=ID"=>$ids)
            )
        );
        $users = [];
        while($user = $rsUsers->fetch()){
            $users[$user["ID"]] = $user;
        }
        return $users;
    }

    // Получаем лайки товара из hl Блока
    private function getLikes($ids){
        $result = getHlblClass(5)::getList(
            array(
                "select"=>array("UF_PRODUCT_ID", "ELEMENT_CNT"),
                "filter"=>array("UF_PRODUCT_ID"=>$ids),
                "group"=>array("UF_PRODUCT_ID"),
                "runtime"=>array(
                    new \Bitrix\Main\Entity\ExpressionField('ELEMENT_CNT', 'COUNT(*)'),
                )
            )
        );

        $likes = [];
        while($like = $result->Fetch()){
            $likes[$like["UF_PRODUCT_ID"]] = $like["ELEMENT_CNT"];
        }
        return $likes;
    }

    // Получаем просмотры товара
    private function getViews($products){
        $views = [];
        foreach($products as $product){
            $fullDetailPageURL = (CMain::IsHTTPS() ? "https://" : "http://").SITE_SERVER_NAME.":80".$product["DETAIL_PAGE_URL"];
            $result = CPage::GetDynamicList(
                $fullDetailPageURL,
                ($by = "s_date"),
                ($order="desc")
            );
            $views[$product["ID"]] = 0;
            while($hit = $result->Fetch()){
                $views[$product["ID"]] += $hit["COUNTER"];
            }
        }
        return $views;
    }
}