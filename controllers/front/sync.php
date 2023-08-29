<?php

//http://localhost/prestashop/presta-7.8.7-addons/module/omniversepricing/sync

class OmniversepricingSyncModuleFrontController extends ModuleFrontController
{

    public function initContent(){
        
        $start = Configuration::get('OMNIVERSEPRICING_CRON_START', '0');
        $date_cron = Configuration::get('OMNIVERSEPRICING_CRON_DATE', '');
        $today = date("j-n-Y");
        if($today != $date_cron){
            $context = Context::getContext();
            $lang_id = $context->language->id;
            $shop_id = $context->shop->id;
            $languages = Language::getLanguages(false);
            $end = 5;
            $not_found = true;
            foreach ($languages as $lang) {
                $products = Product::getProducts($lang['id_lang'], $start, $end, 'id_product', 'ASC');
                $insert_q = '';
    
                if(isset($products) && !empty($products)){
                    $not_found = false;
                    foreach($products as $product){
    
                        $attributes = $this->getProductAttributesInfo($product['id_product']);
            
                        if(isset($attributes) && !empty($attributes)){
    
                            foreach($attributes as $attribute){
                                $insert_q .= $this->create_insert_query($product, $lang['id_lang'], $attribute['id_product_attribute'], $attribute['price']);
                            }
                        }else{
                            $insert_q .= $this->create_insert_query($product, $lang['id_lang']);
                        }
                    }
    
                    $insert_q = rtrim($insert_q, ',' . "\n");
    
                    if($insert_q != "") {
                        $insert_q = "INSERT INTO `" . _DB_PREFIX_ . "omniversepricing_products` (`product_id`, `id_product_attribute`, `id_country`, `id_currency`, `id_group`, `price`, `promo`, `date`, `shop_id`, `lang_id`) VALUES $insert_q";
                        $insertion = Db::getInstance()->execute($insert_q);
                    }
                }
            }
    
            if($not_found){
                Configuration::updateValue('OMNIVERSEPRICING_CRON_START', '0');
                Configuration::updateValue('OMNIVERSEPRICING_CRON_DATE', $today);
            }else{
                $new_start = $start + $end;
                Configuration::updateValue('OMNIVERSEPRICING_CRON_START', $new_start);
            }
        }
        
        die();
    }

    private function create_insert_query($product, $lang_id, $id_attribute = false, $attr_price = false){
        $specific_prices = SpecificPrice::getByProductId($product['id_product'], $id_attribute);
        $price_amount = $product['price'];
        $q = '';
        $context = Context::getContext();
        $shop_id = $context->shop->id;
        $need_default = true;

        if(isset($specific_prices) && !empty($specific_prices)){

            foreach($specific_prices as $specific_price){
                // Reduction
                if(!$specific_price['id_currency'] && !$specific_price['id_group'] && !$specific_price['id_country']){
                    $need_default = false;
                }
                if ($specific_price['reduction_type'] == 'amount') {
                    $reduction_amount = $specific_price['reduction'];

                    if (!$specific_price['id_currency']) {
                        $reduction_amount = Tools::convertPrice($reduction_amount, $context->currency->id);
                    }else{
                        $reduction_amount = Tools::convertPrice($reduction_amount, $specific_price['id_currency']);
                        $attr_price = Tools::convertPrice($attr_price, $specific_price['id_currency']);
                        $price_amount = Tools::convertPrice($price_amount, $specific_price['id_currency']);
                        $price_amount += $attr_price;
                    }

                    $specific_price_reduction = $reduction_amount;

                    // Adjust taxes if required
                    $address = new Address();
                    $use_tax = Configuration::get('OMNIVERSEPRICING_PRICE_WITH_TAX', false);
                    $tax_manager = TaxManagerFactory::getManager($address, Product::getIdTaxRulesGroupByIdProduct((int) $product['id_product'], $context));
                    $product_tax_calculator = $tax_manager->getTaxCalculator();

                    if (!$use_tax && $specific_price['reduction_tax']) {
                        $specific_price_reduction = $product_tax_calculator->removeTaxes($specific_price_reduction);
                    }
                    if ($use_tax && !$specific_price['reduction_tax']) {
                        $specific_price_reduction = $product_tax_calculator->addTaxes($specific_price_reduction);
                    }
                } else {
                    $attr_price = Tools::convertPrice($attr_price, $specific_price['id_currency']);
                    $price_amount = Tools::convertPrice($price_amount, $specific_price['id_currency']);
                    $price_amount += $attr_price;
                    $specific_price_reduction = $price_amount * $specific_price['reduction'];
                }
                $price_amount -= $specific_price_reduction;
                $existing = $this->check_existance($product['id_product'], $lang_id, $price_amount, $specific_price['id_product_attribute'], $specific_price['id_country'], $specific_price['id_currency'], $specific_price['id_group']);
                
                if (empty($existing)) {
                    
                    if($q != ""){
                        $q .= ',';
                    }
                    $q .= "\n" . '(' . $product['id_product'] . ',' . $specific_price['id_product_attribute'] . ',' . $specific_price['id_country'] . ',' . $specific_price['id_currency'] . ',' . $specific_price['id_group'] . ',' . $price_amount . ',1,"' . date('Y-m-d') . '",' . $shop_id . ',' . $lang_id . ')';
                }
            }

        }

        if($id_attribute === false){
            $id_attribute = null;
        }

        if($need_default){
            $price_amount = Product::getPriceStatic(
                (int) $product['id_product'],
                false,
                $id_attribute
            );
            $existing = $this->check_existance($product['id_product'], $lang_id, $price_amount, $id_attribute);
    
            if($id_attribute === null){
                $id_attribute = 0;
            }
    
            if (empty($existing)) {
                if($q != ""){
                    $q .= ',';
                }
                $q .= "\n" . '(' . $product['id_product'] . ',' . $id_attribute . ',0,0,0,' . $price_amount . ',0,"' . date('Y-m-d') . '",' . $shop_id . ',' . $lang_id . ')';
            }
        }

        if($q != ''){
            $q .= ',' . "\n";
        }
        return $q;
    }

    /**
     * Check if price is alredy available for the product
     */
    private function check_existance($prd_id, $lang_id, $price, $id_attr = 0, $country = 0, $currency = 0, $group = 0)
    {
        $context = Context::getContext();
        $shop_id = $context->shop->id;
        $attr_q = '';
        $curre_q = '';
        $countr_q = '';
        $group_q = '';

        if(!$id_attr){
            $id_attr = 0;
        }
        $attr_q = ' AND oc.`id_product_attribute` = ' . $id_attr;
        $curre_q = ' AND oc.`id_currency` = ' . $currency;
        $countr_q = ' AND oc.`id_country` = ' . $country;
        $countr_q = ' AND oc.`id_country` = ' . $group;
        $results = Db::getInstance()->executeS(
            'SELECT *
            FROM `' . _DB_PREFIX_ . 'omniversepricing_products` oc
            WHERE oc.`lang_id` = ' . (int) $lang_id . ' AND oc.`shop_id` = ' . (int) $shop_id . '
            AND oc.`product_id` = ' . (int) $prd_id . ' AND oc.`price` = ' . $price . $attr_q . $curre_q . $countr_q . $group_q
        );

        return $results;
    }

    private function getProductAttributesInfo($id_product, $shop_only = false)
    {
        return Db::getInstance()->executeS('
        SELECT pa.id_product_attribute, pa.price
        FROM `' . _DB_PREFIX_ . 'product_attribute` pa' .
        ($shop_only ? Shop::addSqlAssociation('product_attribute', 'pa') : '') . '
        WHERE pa.`id_product` = ' . (int) $id_product);
    }


}
