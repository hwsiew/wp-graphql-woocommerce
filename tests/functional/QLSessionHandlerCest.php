<?php 

use Firebase\JWT\JWT;

class QLSessionHandlerCest {
    private $product_id;

    public function _before( FunctionalTester $I ) {
        // Create Product
        $this->product_catalog = $I->getCatalog();
    }

    // tests
    public function testCartMutationsWithValidCartSessionToken( FunctionalTester $I ) {
        /**
         * Add item to the cart
         */
        $success = $I->addToCart(
            array(
                'clientMutationId' => 'someId',
                'productId'        => $this->product_catalog['t-shirt'],
                'quantity'         => 5,
            )
        );
        
        $I->assertArrayNotHasKey( 'errors', $success );
        $I->assertArrayHasKey('data', $success );
        $I->assertArrayHasKey('addToCart', $success['data'] );
        $I->assertArrayHasKey('cartItem', $success['data']['addToCart'] );
        $I->assertArrayHasKey('key', $success['data']['addToCart']['cartItem'] );
        $cart_item_key = $success['data']['addToCart']['cartItem']['key'];

        /**
         * Assert existence and validity of "woocommerce-session" HTTP header.
         */
        $I->seeHttpHeaderOnce( 'woocommerce-session' );
        $session_token = $I->grabHttpHeader( 'woocommerce-session' );

        // Decode token
        JWT::$leeway = 60;
        $token_data  = ! empty( $session_token )
            ? JWT::decode( $session_token, 'graphql-woo-cart-session', array( 'HS256' ) )
            : null;

        $I->assertNotEmpty( $token_data );
        $I->assertNotEmpty( $token_data->iss );
        $I->assertNotEmpty( $token_data->iat );
        $I->assertNotEmpty( $token_data->nbf );
        $I->assertNotEmpty( $token_data->exp );
        $I->assertNotEmpty( $token_data->data );
        $I->assertNotEmpty( $token_data->data->customer_id );

        $wp_url = getenv( 'WP_URL' );
        $I->assertEquals( $token_data->iss, $wp_url );

        /**
         * Make a cart query request with "woocommerce-session" HTTP Header and confirm
         * correct cart contents. 
         */
        $query = '
            query {
                cart {
                    contents {
                        nodes {
                            key
                        }
                    }
                }
            }
        ';

        $actual = $I->sendGraphQLRequest( $query, null, array( 'woocommerce-session' => "Session {$session_token}" ) );
        $expected = array(
            'data' => array(
                'cart' => array(
                    'contents' => array(
                        'nodes' => array(
                            array(
                                'key' => $cart_item_key,
                            ),
                        ),
                    ),
                ),
            ),
        );

        $I->assertEquals( $expected, $actual );

        /**
         * Remove item from the cart
         */        
        $success = $I->removeItemsFromCart(
            array(
                'clientMutationId' => 'someId',
                'keys'             => $cart_item_key,
            ),
            array( 'woocommerce-session' => "Session {$session_token}" )
        );
        
        $I->assertArrayNotHasKey( 'errors', $success );
        $I->assertArrayHasKey('data', $success );
        $I->assertArrayHasKey('removeItemsFromCart', $success['data'] );
        $I->assertArrayHasKey('cartItems', $success['data']['removeItemsFromCart'] );
        $I->assertCount( 1, $success['data']['removeItemsFromCart']['cartItems'] );

        /**
         * Make a cart query request with "woocommerce-session" HTTP Header and confirm
         * correct cart contents. 
         */
        $query = '
            query {
                cart {
                    contents {
                        nodes {
                            key
                        }
                    }
                }
            }
        ';

        $actual = $I->sendGraphQLRequest( $query, null, array( 'woocommerce-session' => "Session {$session_token}" ) );
        $expected = array(
            'data' => array(
                'cart' => array(
                    'contents' => array(
                        'nodes' => array(),
                    ),
                ),
            ),
        );

        $I->assertEquals( $expected, $actual );

        /**
         * Restore item to the cart
         */        
        $success = $I->restoreCartItems(
            array(
                'clientMutationId' => 'someId',
                'keys'             => array( $cart_item_key ),
            ),
            array( 'woocommerce-session' => "Session {$session_token}" )
        );
        
        $I->assertArrayNotHasKey( 'errors', $success );
        $I->assertArrayHasKey('data', $success );
        $I->assertArrayHasKey('restoreCartItems', $success['data'] );
        $I->assertArrayHasKey('cartItems', $success['data']['restoreCartItems'] );
        $I->assertCount( 1, $success['data']['restoreCartItems']['cartItems'] );

        /**
         * Make a cart query request with "woocommerce-session" HTTP Header and confirm
         * correct cart contents. 
         */
        $query = '
            query {
                cart {
                    contents {
                        nodes {
                            key
                        }
                    }
                }
            }
        ';

        $actual = $I->sendGraphQLRequest( $query, null, array( 'woocommerce-session' => "Session {$session_token}" ) );
        $expected = array(
            'data' => array(
                'cart' => array(
                    'contents' => array(
                        'nodes' => array(
                            array(
                                'key' => $cart_item_key,
                            ),
                        ),
                    ),
                ),
            ),
        );

        $I->assertEquals( $expected, $actual );
    }

    public function testCartMutationsWithInvalidCartSessionToken( FunctionalTester $I ) {
        /**
         * Add item to cart and retrieve session token to corrupt.
         */
        $success = $I->addToCart(
            array(
                'clientMutationId' => 'someId',
                'productId'        => $this->product_catalog['t-shirt'],
                'quantity'         => 1,
            )
        );
        
        $I->assertArrayNotHasKey( 'errors', $success );
        $I->assertArrayHasKey('data', $success );
        $I->assertArrayHasKey('addToCart', $success['data'] );
        $I->assertArrayHasKey('cartItem', $success['data']['addToCart'] );
        $I->assertArrayHasKey('key', $success['data']['addToCart']['cartItem'] );
        $cart_item_key = $success['data']['addToCart']['cartItem']['key'];

        /**
         * Retrieve session token from "woocommerce-session" HTTP response header.
         */
        $I->seeHttpHeaderOnce( 'woocommerce-session' );
        $valid_token = $I->grabHttpHeader( 'woocommerce-session' );

        // Decode token
        $token_data = ! empty( $valid_token )
            ? JWT::decode( $valid_token, 'graphql-woo-cart-session', array( 'HS256' ) )
            : null;

        /**
         * Attempt to add item to the cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         */
        $invalid_token                    = $token_data;
        $invalid_token->data->customer_id = '';
        $invalid_token                    = JWT::encode( $invalid_token, 'graphql-woo-cart-session' );

        $failed = $I->addToCart(
            array(
                'clientMutationId' => 'someId',
                'productId'        => $this->product_catalog['t-shirt'],
                'quantity'         => 1,
            ),
            array( 'woocommerce-session' => "Session {$invalid_token}" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to remove item from the cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         */
        $invalid_token      = $token_data;
        $invalid_token->iss = '';
        $invalid_token      = JWT::encode( $invalid_token, 'graphql-woo-cart-session' );

        $failed = $I->removeItemsFromCart(
            array(
                'clientMutationId' => 'someId',
                'keys'             => $cart_item_key,
            ),
            array( 'woocommerce-session' => "Session {$invalid_token}" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to update quantity of item in the cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         */
        $failed = $I->updateItemQuantities(
            array(
                'clientMutationId' => 'someId',
                'items'            => array(
                    array( 'key' => $cart_item_key, 'quantity' => 0 ),
                ),
            ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to empty cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         */
        $failed = $I->emptyCart(
            array( 'clientMutationId' => 'someId', ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to add fee on cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         */
        $failed = $I->addFee(
            array(
                'clientMutationId' => 'someId',
                'name'             => 'extra_fee',
                'amount'           => 49.99,
            ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to apply coupon on cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         * 
         * @Note: No coupons exist in the database, but mutation should fail before that becomes a factor.
         */
        $failed = $I->applyCoupon(
            array(
                'clientMutationId' => 'someId',
                'code'             => 'some_coupon',
            ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to remove coupon from cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         * 
         * @Note: No coupons exist on the cart, but mutation should failed before that becomes a factor.
         */
        $failed = $I->removeCoupons(
            array(
                'clientMutationId' => 'someId',
                'codes'            => array( 'some_coupon' ),
            ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to restore item to the cart with invalid session token.
         * GraphQL should throw an error and mutation will fail.
         * 
         * @Note: No items have been removed from the cart in this session, 
         * but mutation should failed before that becomes a factor.
         */
        $failed = $I->restoreCartItems(
            array(
                'clientMutationId' => 'someId',
                'keys'             => array( $cart_item_key ),
            ),
            array( 'woocommerce-session' => "Session invalid-jwt-token-string" )
        );
        
        $I->assertArrayHasKey( 'errors', $failed );

        /**
         * Attempt to query cart with invalid session token.
         * GraphQL should throw an error and query will fail.
         */
        $query = '
            query {
                cart {
                    contents {
                        nodes {
                            key
                        }
                    }
                }
            }
        ';

        $failed = $I->sendGraphQLRequest(
            $query,
            null,
            array( 'woocommerce-session' => 'Session invalid-jwt-token-string' )
        );

        $I->assertArrayHasKey( 'errors', $failed );
    }
}
