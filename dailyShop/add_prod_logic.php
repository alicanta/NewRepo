<?php

 if(isset($_POST['edit_product']))
	{
	include("config.php");
	$hiddenidtoedit=$_POST['hidden_eid'];
	$id3= $_POST['p_id'];
	$name3= $_POST['p_name'];
	$price3= $_POST['p_price'];
	//$quantity3= $_POST['p_quantity'];
	$category3= $_POST['p_category'];
				   
		 if(isset($_FILES['p_image']))
		 {
			$tempn=$_FILES['p_image']['tmp_name'];
			$nm1=$_FILES['p_image']['name'];
		 	if(move_uploaded_file($tempn,"../uploadsnew/".$nm1))
		 	{
		 		echo "image uploaded successfully";
				$image3=$nm1;
		
			}
		 }
	
	$stmt=$conn->prepare(" UPDATE product_table  SET id=?,name=?,price=?,image=?,category=? WHERE id=?");
	$stmt->bind_param("ssiisss",$id3,$name3,$price3,$image3,$category3,$hiddenidtoedit);
	$stmt->execute();
	$stmt->close();
	$conn->close();
	//header("location:manageprod.php");
}

?>