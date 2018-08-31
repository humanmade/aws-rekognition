<table width="100%">
	<tr>
		<td align="left" width="70" colspan="2">
			<strong>AWS Rekognition</strong><br />
			A lightweight plugin to add keywords to WordPress image uploads via automatic feature detection. Requires S3 Uploads.
		</td>
	</tr>
	<tr>
		<td>
			A <strong><a href="https://hmn.md/">Human Made</a></strong> project. Maintained by @joehoyle.
		</td>
		<td align="center">
			<img src="https://hmn.md/content/themes/hmnmd/assets/images/hm-logo.svg" width="100" />
		</td>
	</tr>
</table>

AWS Rekognition can auto detect image features, providing automatic labeling of uploaded image files. This is then used to enhance the WordPress media library search.

### Demo

![picture](https://user-images.githubusercontent.com/161683/34051356-473686a4-e18c-11e7-999a-02e31980c897.gif)

### Usage

By default the plugin assumes you have created an AWS access key that has permission to access the Rekognition service.

The default region is `us-east-1`.

Configure the client by defining the following constants:

```php
<?php
define( 'AWS_REKOGNITION_REGION', 'eu-west-1' );
define( 'AWS_REKOGNITION_KEY', '*************' );
define( 'AWS_REKOGNITION_SECRET', '*************' );
```

If using the plugin on AWS servers and have an instance profile with permissions to use Rekognition set up you can omit defining the key and secret constants.

### Features

#### Label detection
#### Moderation label detection
#### Face detection
#### Celebrity recognition
#### Text detection
