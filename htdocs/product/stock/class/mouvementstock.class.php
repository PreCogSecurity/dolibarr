<?php
/* Copyright (C) 2003-2006 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2005-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2011      Jean Heimburger      <jean@tiaris.info>
 * Copyright (C) 2014	   Cedric GROSS	        <c.gross@kreiz-it.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/product/stock/class/mouvementstock.class.php
 *	\ingroup    stock
 *	\brief      File of class to manage stock movement (input or output)
 */


/**
 *	Class to manage stock movements
 */
class MouvementStock extends CommonObject
{
	var $product_id;
	var $entrepot_id;
	var $qty;
	var $type;


    /**
	 *  Constructor
	 *
	 *  @param      DoliDB		$db      Database handler
     */
	function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *	Add a movement of stock (in one direction only)
	 *
	 *	@param		User	$user			User object
	 *	@param		int		$fk_product		Id of product
	 *	@param		int		$entrepot_id	Id of warehouse
	 *	@param		int		$qty			Qty of movement (can be <0 or >0 depending on parameter type)
	 *	@param		int		$type			Direction of movement:
	 *										0=input (stock increase after stock transfert), 1=output (stock decrease after stock transfer),
	 *										2=output (stock decrease), 3=input (stock increase)
	 *                                      Note that qty should be > 0 with 0 or 3, < 0 with 1 or 2.
	 *	@param		int		$price			Unit price HT of product, used to calculate average weighted price (PMP in french). If 0, average weighted price is not changed.
	 *	@param		string	$label			Label of stock movement
	 *	@param		string	$inventorycode	Inventory code
	 *	@param		string	$datem			Force date of movement
	 *	@param		date	$eatby			eat-by date
	 *	@param		date	$sellby			sell-by date
	 *	@param		string	$batch			batch number
	 *	@param		boolean	$skip_batch		If set to true, stock movement is done without impacting batch record
	 * 	@param		int		$id_product_batch	Id product_batch (when skip_batch is flase and we already know which record of product_batch to use)
	 *	@return		int						<0 if KO, 0 if fk_product is null, >0 if OK
	 */
	function _create($user, $fk_product, $entrepot_id, $qty, $type, $price=0, $label='', $inventorycode='', $datem='',$eatby='',$sellby='',$batch='',$skip_batch=false, $id_product_batch=0)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
		$error = 0;
		dol_syslog(get_class($this)."::_create start userid=$user->id, fk_product=$fk_product, warehouse=$entrepot_id, qty=$qty, type=$type, price=$price, label=$label, inventorycode=$inventorycode, datem=".$datem.", eatby=".$eatby.", sellby=".$sellby.", batch=".$batch.", skip_batch=".$skip_batch);

		// Clean parameters
		if (empty($price)) $price=0;
		$now=(! empty($datem) ? $datem : dol_now());

		// Check parameters
		if (empty($fk_product)) return 0;
		if ($eatby < 0)
		{
			$this->errors[]='ErrorBadValueForParameterEatBy';
			return -1;
		}
		if ($sellby < 0)
		{
			$this->errors[]='ErrorBadValueForParameterEatBy';
			return -1;
		}

		// Set properties of movement
		$this->product_id = $fk_product;
		$this->entrepot_id = $entrepot_id;
		$this->qty = $qty;
		$this->type = $type;

		$mvid = 0;

		$product = new Product($this->db);
		$result=$product->fetch($fk_product);
		if ($result < 0)
		{
			dol_print_error('',"Failed to fetch product");
			return -1;
		}

		$this->db->begin();

		$product->load_stock();

		// Test if product require batch data. If yes, and there is not, we throw an error.
		if (! empty($conf->productbatch->enabled) && $product->hasbatch() && ! $skip_batch)
		{
			if (empty($batch))
			{
				$this->errors[]=$langs->trans("ErrorTryToMakeMoveOnProductRequiringBatchData", $product->name);
				dol_syslog("Try to make a movement of a product with status_batch on without any batch data");

				$this->db->rollback();
				return -2;
			}

			// FIXME Code not complete to implement this
			// Check table llx_product_lot from batchnumber for same product
			// If found and eatby/sellby defined into table and provided and differs, return error
			// If found and eatby/sellby defined into table and not provided, we take value from table
			// If found and eatby/sellby not defined into table and provided, we update table
			// If found and eatby/sellby not defined into table and not provided, we do nothing
			// If not found, we add record
			$sql = "SELECT pb.rowid, pb.batch, pb.eatby, pb.sellby FROM ".MAIN_DB_PREFIX."product_lot as pb";
			$sql.= " WHERE pb.fk_product = ".$fk_product." AND pb.batch = '".$this->db->escape($batch)."'";
            dol_syslog(get_class($this)."::_create scan serial for this product to check if eatby and sellby match", LOG_DEBUG);
            $resql = $this->db->query($sql);
            if ($resql)
            {
            	$num = $this->db->num_rows($resql);
            	$i=0;
            	if ($num > 0)
            	{
                	while ($i < $num)
                	{
                		$obj = $this->db->fetch_object($resql);
                        if ($obj->eatby)
                        {
                            if ($eatby)
                            {
                        		if ($this->db->jdate($obj->eatby) != $eatby)  // If found and eatby/sellby defined into table and provided and differs, return error
                                {
                                    $this->errors[]=$langs->trans("ThisSerialAlreadyExistWithDifferentDate", $batch, dol_print_date($this->db->jdate($obj->eatby)), dol_print_date($eatby));
                                    dol_syslog($langs->transnoentities("ThisSerialAlreadyExistWithDifferentDate", $batch, dol_print_date($this->db->jdate($obj->eatby)), dol_print_date($eatby)), LOG_ERR);
                                    $this->db->rollback();
                                    return -3;
                                }
                            }
                            else
                            {
                                $eatby = $obj->eatby; // If found and eatby/sellby defined into table and not provided, we take value from table
                            }
                        }
                        else
                        {
                            if ($eatby) // If found and eatby/sellby not defined into table and provided, we update table
                            {
                                $productlot = new Productlot($this->db);
                                $result = $productlot->fetch($obj->rowid);
                                $productlot->eatby = $eatby;
                                $result = $productlot->update($user);
                                if ($result <= 0)
                                {
                                    $this->error = $productlot->error;
                                    $this->errors = $productlot->errors;
                                    $this->db->rollback();
                                    return -5;
                                }                            
                            }
                        }
                        if ($obj->sellby)
                        {
                            if ($sellby)
                            {
                                if ($this->db->jdate($obj->sellby) != $sellby) // If found and eatby/sellby defined into table and provided and differs, return error
                        		{
            						$this->errors[]=$langs->trans("ThisSerialAlreadyExistWithDifferentDate", $batch, dol_print_date($this->db->jdate($obj->sellby)), dol_print_date($sellby));
            						dol_syslog($langs->transnoentities("ThisSerialAlreadyExistWithDifferentDate", $batch, dol_print_date($this->db->jdate($obj->sellby)), dol_print_date($sellby)), LOG_ERR);
            						$this->db->rollback();
                        			return -3;
                        		}
                            }
                            else
                            {
                                $sellby = $obj->sellby; // If found and eatby/sellby defined into table and not provided, we take value from table
                            }
                        }
                	    else
                        {
                            if ($sellby) // If found and eatby/sellby not defined into table and provided, we update table
                            {
                                $productlot = new Productlot($this->db);
                                $result = $productlot->fetch($obj->rowid);
                                $productlot->sellby = $sellby;
                                $result = $productlot->update($user);
                                if ($result <= 0)
                                {
                                    $this->error = $productlot->error;
                                    $this->errors = $productlot->errors;
                                    $this->db->rollback();
                                    return -5;
                                }
                            }
                        }
                        
                        $i++;
                	}
            	}
            	else
            	{
            	    $productlot = new Productlot($this->db);
            	    $productlot->fk_product = $fk_product;
            	    $productlot->batch = $batch;
            	    $result = $productlot->create($user);
            	    if ($result <= 0)
            	    {
            	        $this->error = $productlot->error;
            	        $this->errors = $productlot->errors;
            	        $this->db->rollback();
            	        return -4;
            	    }
            	}
            }
            else
			{
            	dol_print_error($this->db);
            	$this->db->rollback();
            	return -1;
			}
		}

		// TODO Check qty is ok for stock move.
		if (! empty($conf->productbatch->enabled) && $product->hasbatch() && ! $skip_batch)
		{

		}
		else
		{

		}

		// Define if we must make the stock change (If product type is a service or if stock is used also for services)
		$movestock=0;
		if ($product->type != Product::TYPE_SERVICE || ! empty($conf->global->STOCK_SUPPORTS_SERVICES)) $movestock=1;

		if ($movestock && $entrepot_id > 0)	// Change stock for current product, change for subproduct is done after
		{
			if(!empty($this->origin)) {			// This is set by caller for tracking reason
				$origintype = $this->origin->element;
				$fk_origin = $this->origin->id;
			} else {
				$origintype = '';
				$fk_origin = 0;
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."stock_mouvement(";
			$sql.= " datem, fk_product, batch, eatby, sellby,";
			$sql.= " fk_entrepot, value, type_mouvement, fk_user_author, label, inventorycode, price, fk_origin, origintype";
			$sql.= ")";
			$sql.= " VALUES ('".$this->db->idate($now)."', ".$this->product_id.", ";
			$sql.= " ".($batch?"'".$batch."'":"null").", ";
			$sql.= " ".($eatby?"'".$this->db->idate($eatby)."'":"null").", ";
			$sql.= " ".($sellby?"'".$this->db->idate($sellby)."'":"null").", ";
			$sql.= " ".$this->entrepot_id.", ".$this->qty.", ".$this->type.",";
			$sql.= " ".$user->id.",";
			$sql.= " '".$this->db->escape($label)."',";
			$sql.= " ".($inventorycode?"'".$this->db->escape($inventorycode)."'":"null").",";
			$sql.= " '".price2num($price)."',";
			$sql.= " '".$fk_origin."',";
			$sql.= " '".$origintype."'";
			$sql.= ")";

			dol_syslog(get_class($this)."::_create", LOG_DEBUG);
			$resql = $this->db->query($sql);
			if ($resql)
			{
				$mvid = $this->db->last_insert_id(MAIN_DB_PREFIX."stock_mouvement");
				$this->id = $mvid;
			}
			else
			{
				$this->errors[]=$this->db->lasterror();
				$error = -1;
			}

			// Define current values for qty and pmp
			$oldqty=$product->stock_reel;
			$oldpmp=$product->pmp;
			$oldqtywarehouse=0;

			// Test if there is already a record for couple (warehouse / product)
			$alreadyarecord = 0;
			if (! $error)
			{
				$sql = "SELECT rowid, reel FROM ".MAIN_DB_PREFIX."product_stock";
				$sql.= " WHERE fk_entrepot = ".$entrepot_id." AND fk_product = ".$fk_product;		// This is a unique key

				dol_syslog(get_class($this)."::_create", LOG_DEBUG);
				$resql=$this->db->query($sql);
				if ($resql)
				{
					$obj = $this->db->fetch_object($resql);
					if ($obj)
					{
						$alreadyarecord = 1;
						$oldqtywarehouse = $obj->reel;
						$fk_product_stock = $obj->rowid;
					}
					$this->db->free($resql);
				}
				else
				{
					$this->errors[]=$this->db->lasterror();
					$error = -2;
				}
			}

			// Calculate new PMP.
			$newpmp=0;
			if (! $error)
			{
				// Note: PMP is calculated on stock input only (type of movement = 0 or 3). If type == 0 or 3, qty should be > 0.
				// Note: Price should always be >0 or 0. PMP should be always >0 (calculated on input)
				if (($type == 0 || $type == 3) && $price > 0)
				{
					$oldqtytouse=($oldqty >= 0?$oldqty:0);
					// We make a test on oldpmp>0 to avoid to use normal rule on old data with no pmp field defined
					if ($oldpmp > 0) $newpmp=price2num((($oldqtytouse * $oldpmp) + ($qty * $price)) / ($oldqtytouse + $qty), 'MU');
					else
					{
						$newpmp=$price; // For this product, PMP was not yet set. We set it to input price.
					}
					//print "oldqtytouse=".$oldqtytouse." oldpmp=".$oldpmp." oldqtywarehousetouse=".$oldqtywarehousetouse." ";
					//print "qty=".$qty." newpmp=".$newpmp;
					//exit;
				}
				else if ($type == 1 || $type == 2)
				{
					// After a stock decrease, we don't change value of PMP for product.
					$newpmp = $oldpmp;
				}
				else
				{
					$newpmp = $oldpmp;
				}
			}

			// Update stock quantity
			if (! $error)
			{
				if ($alreadyarecord > 0)
				{
					$sql = "UPDATE ".MAIN_DB_PREFIX."product_stock SET reel = reel + ".$qty;
					$sql.= " WHERE fk_entrepot = ".$entrepot_id." AND fk_product = ".$fk_product;
				}
				else
				{
					$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_stock";
					$sql.= " (reel, fk_entrepot, fk_product) VALUES ";
					$sql.= " (".$qty.", ".$entrepot_id.", ".$fk_product.")";
				}

				dol_syslog(get_class($this)."::_create", LOG_DEBUG);
				$resql=$this->db->query($sql);
				if (! $resql)
				{
					$this->errors[]=$this->db->lasterror();
					$error = -3;
				}
				else if (empty($fk_product_stock))
				{
					$fk_product_stock = $this->db->last_insert_id(MAIN_DB_PREFIX."product_stock");
				}

			}

			// Update detail stock for batch product
			if (! $error && ! empty($conf->productbatch->enabled) && $product->hasbatch() && ! $skip_batch)
			{
				if ($id_product_batch > 0)
				{
				    $result=$this->createBatch($id_product_batch, $qty);
				}
				else
				{
			        $param_batch=array('fk_product_stock' =>$fk_product_stock, 'batchnumber'=>$batch);
				    $result=$this->createBatch($param_batch, $qty);
				}
				if ($result<0) $error++;
			}

			// Update PMP and denormalized value of stock qty at product level
			if (! $error)
			{
				// $sql = "UPDATE ".MAIN_DB_PREFIX."product SET pmp = ".$newpmp.", stock = ".$this->db->ifsql("stock IS NULL", 0, "stock") . " + ".$qty;
				// $sql.= " WHERE rowid = ".$fk_product;
    			// Update pmp + denormalized fields because we change content of produt_stock. Warning: Do not use "SET p.stock", does not works with pgsql
				$sql = "UPDATE ".MAIN_DB_PREFIX."product as p SET p.pmp = ".$newpmp.", ";
				$sql.= " stock=(SELECT SUM(ps.reel) FROM ".MAIN_DB_PREFIX."product_stock ps WHERE ps.fk_product = p.rowid)";
				$sql.= " WHERE rowid = ".$fk_product;
				
				dol_syslog(get_class($this)."::_create", LOG_DEBUG);
				$resql=$this->db->query($sql);
				if (! $resql)
				{
					$this->errors[]=$this->db->lasterror();
					$error = -4;
				}
			}
			
		    // If stock is now 0, we can remove entry into llx_product_stock, but only if there is no child lines into llx_product_batch (detail of batch, because we can imagine
		    // having a lot1/qty=X and lot2/qty=-X, so 0 but we must not loose repartition of different lot.
		    $sql="DELETE FROM ".MAIN_DB_PREFIX."product_stock WHERE reel = 0 AND rowid NOT IN (SELECT fk_product_stock FROM ".MAIN_DB_PREFIX."product_batch as pb)";
		    $resql=$this->db->query($sql);
		    // We do not test error, it can fails if there is child in batch details
		}

		// Add movement for sub products (recursive call)
		if (! $error && ! empty($conf->global->PRODUIT_SOUSPRODUITS) && empty($conf->global->INDEPENDANT_SUBPRODUCT_STOCK))
		{
			$error = $this->_createSubProduct($user, $fk_product, $entrepot_id, $qty, $type, 0, $label, $inventorycode);	// we use 0 as price, because pmp is not changed for subproduct
		}

		if ($movestock && ! $error)
		{
            // Call trigger
            $result=$this->call_trigger('STOCK_MOVEMENT',$user);
            if ($result < 0) $error++;
            // End call triggers
		}

		if (! $error)
		{
			$this->db->commit();
			return $mvid;
		}
		else
		{
			$this->db->rollback();
			dol_syslog(get_class($this)."::_create error code=".$error, LOG_ERR);
			return -6;
		}
	}


	/**
	 *  Create movement in database for all subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$idProduct		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$type			Type
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of movement
	 *  @param		string	$inventorycode	Inventory code
	 * 	@return 	int     				<0 if KO, 0 if OK
	 */
	function _createSubProduct($user, $idProduct, $entrepot_id, $qty, $type, $price=0, $label='', $inventorycode='')
	{
		$error = 0;
		$pids = array();
		$pqtys = array();

		$sql = "SELECT fk_product_pere, fk_product_fils, qty";
		$sql.= " FROM ".MAIN_DB_PREFIX."product_association";
		$sql.= " WHERE fk_product_pere = ".$idProduct;
		$sql.= " AND incdec = 1";

		dol_syslog(get_class($this)."::_createSubProduct", LOG_DEBUG);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$i=0;
			while ($obj=$this->db->fetch_object($resql))
			{
				$pids[$i]=$obj->fk_product_fils;
				$pqtys[$i]=$obj->qty;
				$i++;
			}
			$this->db->free($resql);
		}
		else
		{
			$error = -2;
		}

		// Create movement for each subproduct
		foreach($pids as $key => $value)
		{
			$tmpmove = clone $this;
			$tmpmove->_create($user, $pids[$key], $entrepot_id, ($qty * $pqtys[$key]), $type, 0, $label, $inventorycode);		// This will also call _createSubProduct making this recursive
			unset($tmpmove);
		}

		return $error;
	}


	/**
	 *	Decrease stock for product and subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$fk_product		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of stock movement
	 * 	@param		string	$datem			Force date of movement
	 *	@param		date	$eatby			eat-by date
	 *	@param		date	$sellby			sell-by date
	 *	@param		string	$batch			batch number
	 * 	@param		int		$id_product_batch	Id product_batch
	 * 	@return		int						<0 if KO, >0 if OK
	 */
	function livraison($user, $fk_product, $entrepot_id, $qty, $price=0, $label='', $datem='', $eatby='', $sellby='', $batch='', $id_product_batch=0)
	{
	    $skip_batch = empty($conf->productbatch->enabled);

	    return $this->_create($user, $fk_product, $entrepot_id, (0 - $qty), 2, $price, $label, '', $datem, $eatby, $sellby, $batch, $skip_batch, $id_product_batch);
	}

	/**
	 *	Increase stock for product and subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$fk_product		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of stock movement
	 *	@param		date	$eatby			eat-by date
	 *	@param		date	$sellby			sell-by date
	 *	@param		string	$batch			batch number
	 *	@return		int						<0 if KO, >0 if OK
	 */
	function reception($user, $fk_product, $entrepot_id, $qty, $price=0, $label='', $eatby='', $sellby='', $batch='')
	{
		return $this->_create($user, $fk_product, $entrepot_id, $qty, 3, $price, $label, '', '', $eatby, $sellby, $batch);
	}


	/**
	 * Return nb of subproducts lines for a product
	 *
	 * @param      int		$id				Id of product
	 * @return     int						<0 if KO, nb of subproducts if OK
	 */
	function nbOfSubProdcuts($id)
	{
		$nbSP=0;

		$resql = "SELECT count(*) as nb FROM ".MAIN_DB_PREFIX."product_association";
		$resql.= " WHERE fk_product_pere = ".$id;
		if ($this->db->query($resql))
		{
			$obj=$this->db->fetch_object($resql);
			$nbSP=$obj->nb;
		}
		return $nbSP;
	}

	/**
	 * Count number of product in stock before a specific date
	 *
	 * @param 	int			$productidselected		Id of product to count
	 * @param 	timestamp	$datebefore				Date limit
	 * @return	int			Number
	 */
	function calculateBalanceForProductBefore($productidselected, $datebefore)
	{
		$nb=0;

		$sql = 'SELECT SUM(value) as nb from '.MAIN_DB_PREFIX.'stock_mouvement';
		$sql.= ' WHERE fk_product = '.$productidselected;
		$sql.= " AND datem < '".$this->db->idate($datebefore)."'";

		dol_syslog(get_class($this).__METHOD__.'', LOG_DEBUG);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$obj=$this->db->fetch_object($resql);
			if ($obj) $nb = $obj->nb;
			return (empty($nb)?0:$nb);
		}
		else
		{
			dol_print_error($this->db);
			return -1;
		}
	}

	/**
	 * Create or update batch record (update table llx_product_batch). No check is done here, done by parent.
	 *
	 * @param	array|int	$dluo	      Could be either 
	 *                                     - int if row id of product_batch table
	 *                                     - or complete array('fk_product_stock'=>, 'batchnumber'=>)
	 * @param	int			$qty	      Quantity of product with batch number. May be a negative amount.
	 * @return 	int   				      <0 if KO, else return productbatch id
	 */
	private function createBatch($dluo, $qty)
	{
	    global $user;
	    
		$pdluo=new Productbatch($this->db);

		$result=0;

		// Try to find an existing record with same batch number or id
		if (is_numeric($dluo)) 
		{
			$result=$pdluo->fetch($dluo);
			if (empty($pdluo->id))
			{
				// We didn't find the line. May be it was deleted before by a previous move in same transaction.
				$this->error = 'Error. You ask a move on a record for a serial that does not exists anymore. May be you take the same serial on same warehouse several times in same shipment or it was used by another shipment. Remove this shipment and prepare another one.';
				$this->errors[] = $this->error;
				$result = -2;
			}
		}
		else if (is_array($dluo)) 
		{
			if (isset($dluo['fk_product_stock'])) 
			{
				$vfk_product_stock=$dluo['fk_product_stock'];
				$vbatchnumber = $dluo['batchnumber'];
				
				$result = $pdluo->find($vfk_product_stock,'','',$vbatchnumber);  // Search on batch number only (eatby and sellby are deprecated here)
			}
			else 
			{
				dol_syslog(get_class($this)."::createBatch array param dluo must contain at least key fk_product_stock".$error, LOG_ERR);
				$result = -1;
			}
		} 
		else
		{
			dol_syslog(get_class($this)."::createBatch error invalid param dluo".$error, LOG_ERR);
			$result = -1;
		}

		if ($result >= 0)
		{
			// No error
			if ($pdluo->id > 0)		// product_batch record found
			{
				//print "Avant ".$pdluo->qty." Apres ".($pdluo->qty + $qty)."<br>";
				$pdluo->qty += $qty;
				if ($pdluo->qty == 0) 
				{
					$result=$pdluo->delete($user,1);
				} else {
					$result=$pdluo->update($user,1);
				}
			}
			else					// product_batch record not found
			{
				$pdluo->fk_product_stock=$vfk_product_stock;
				$pdluo->qty = $qty;
				$pdluo->eatby = $veatby;
				$pdluo->sellby = $vsellby;
				$pdluo->batch = $vbatchnumber;
				
				$result=$pdluo->create($user,1);
				if ($result < 0)
				{
					$this->error=$pdluo->error;
					$this->errors=$pdluo->errors;
				}
			}
		}
		
		return $result;
	}

	/**
	 * Return Url link of origin object
	 * 
	 * @param  int     $fk_origin      Id origin
	 * @param  int     $origintype     Type origin
	 * @return string
	 */
	function get_origin($fk_origin, $origintype) 
	{
	    $origin='';
	    
		switch ($origintype) {
			case 'commande':
				require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
				$origin = new Commande($this->db);
				break;
			case 'shipping':
				require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
				$origin = new Expedition($this->db);
				break;
			case 'facture':
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$origin = new Facture($this->db);
				break;
			case 'order_supplier':
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
				$origin = new CommandeFournisseur($this->db);
				break;
			case 'invoice_supplier':
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				$origin = new FactureFournisseur($this->db);
				break;
				
			default:
				if ($origintype)
				{
					$result=dol_include_once('/'.$origintype.'/class/'.$origintype.'.class.php');
					if ($result)
					{
					   $classname = ucfirst($origintype);
					   $origin = new $classname($this->db);
					}
				}
				break;
		}

		if (empty($origin) || ! is_object($origin)) return '';
		
		if ($origin->fetch($fk_origin) > 0) {
			return $origin->getNomUrl(1);
		}

		return '';
	}


	/**
     *  Initialise an instance with random values.
     *  Used to build previews or test instances.
     *	id must be 0 if object instance is a specimen.
     *
     *  @return	void
     */
    function initAsSpecimen()
    {
        global $user,$langs,$conf,$mysoc;

        // Initialize parameters
        $this->id=0;

        // There is no specific properties. All data into insert are provided as method parameter.
    }
}
